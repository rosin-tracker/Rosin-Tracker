<?php

declare(strict_types=1);

namespace RosinTracker;

use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RosinTracker\Domain\BatchData;
use RosinTracker\Domain\BatchFormNormalizer;
use RosinTracker\Domain\BatchTemplateData;
use RosinTracker\Domain\PassData;
use RosinTracker\Domain\PassFormNormalizer;
use RosinTracker\Domain\ValidationException;
use RosinTracker\Http\Request;
use RosinTracker\Http\Response;
use RosinTracker\Http\Router;
use RosinTracker\Repository\AnalyticsRepository;
use RosinTracker\Repository\AuthenticationRepository;
use RosinTracker\Repository\BatchRepository;
use RosinTracker\Repository\DashboardRepository;
use RosinTracker\Repository\OwnerRepository;
use RosinTracker\Repository\PassRepository;
use RosinTracker\Repository\PhotoRepository;
use RosinTracker\Repository\PresetRepository;
use RosinTracker\Repository\SettingsRepository;
use RosinTracker\Repository\TemplateRepository;
use RosinTracker\Security\Auth;
use RosinTracker\Security\AuthenticationException;
use RosinTracker\Security\Csrf;
use RosinTracker\Security\LocalSecondFactor;
use RosinTracker\Security\OidcService;
use RosinTracker\Security\SecretCipher;
use RosinTracker\Security\Session;
use RosinTracker\Storage\BackupService;
use RosinTracker\Storage\PhotoStorage;
use RosinTracker\Storage\PhotoUploadException;
use RosinTracker\Support\ExternalUrl;
use RosinTracker\Support\Units;
use RosinTracker\Support\PasswordPolicy;
use RuntimeException;
use Throwable;

final class Application
{
    private const PENDING_LOCAL_LOGIN_KEY = 'pending_local_login';
    private const TOTP_ENROLLMENT_KEY = 'totp_enrollment';
    private const RECOVERY_CODES_KEY = 'recovery_codes_once';
    private const OIDC_FLOW_KEY = 'oidc_flow';
    private const AUTHENTICATION_CHANGE_KEY = 'authentication_change_authorization';
    private const PENDING_LOGIN_LIFETIME = 300;
    private const TOTP_ENROLLMENT_LIFETIME = 900;
    private const RECOVERY_CODES_LIFETIME = 600;
    private const OIDC_FLOW_LIFETIME = 600;

    /** @var array<string, string> */
    private const BATCH_SORTS = [
        'date' => 'pressed_desc',
        'strain' => 'strain_asc',
        'yield' => 'yield_desc',
        'amount' => 'output_desc',
    ];

    /** @var array<string, array<string, string>> */
    private const PRESET_FIELDS = [
        'start_material' => ['label' => 'Start Materials', 'type' => 'text', 'description' => 'Material choices shown on the batch form.', 'placeholder' => 'Flower'],
        'strain' => ['label' => 'Strains', 'type' => 'text', 'description' => 'Frequently reused strain or cultivar names.', 'placeholder' => 'Blue Dream'],
        'bag' => ['label' => 'Bags', 'type' => 'bag', 'description' => 'Saved bag brands, dimensions, and micron ratings.', 'placeholder' => 'The Press Club · 2 × 4 in · 90 μm'],
    ];

    /** @var list<string> */
    private const MATERIAL_CHART_COLORS = [
        '#75beff',
        '#73c991',
        '#dcdcaa',
        '#c586c0',
        '#ce9178',
        '#4ec9b0',
        '#d16d9e',
        '#b5cea8',
    ];

    /** @var list<string> */
    private const MATERIAL_CHART_MARKERS = [
        'burst',
        'dot',
        'diamond',
        'square',
        'triangle',
        'cross',
    ];

    private readonly PDO $database;
    private readonly Session $session;
    private readonly Csrf $csrf;
    private readonly OwnerRepository $owners;
    private readonly Auth $auth;
    private readonly View $view;
    private readonly DashboardRepository $dashboard;
    private readonly BatchRepository $batches;
    private readonly PassRepository $passes;
    private readonly PresetRepository $presets;
    private readonly TemplateRepository $templates;
    private readonly PhotoRepository $photos;
    private readonly AnalyticsRepository $analytics;
    private readonly SettingsRepository $settings;
    private readonly string $externalUrl;
    private readonly AuthenticationRepository $authentication;
    private readonly LocalSecondFactor $localSecondFactor;
    private readonly OidcService $oidc;
    private readonly BatchFormNormalizer $batchNormalizer;
    private readonly PassFormNormalizer $passNormalizer;
    private readonly PhotoStorage $photoStorage;
    private readonly BackupService $backups;
    private readonly Router $router;

    public function __construct(private readonly Config $config)
    {
        $this->database = (new Database($config))->connection();
        $this->settings = new SettingsRepository($this->database);
        $this->externalUrl = $this->resolveExternalUrl();
        $this->session = new Session(
            str_starts_with($this->externalUrl, 'https://'),
        );
        $this->session->start();
        $this->csrf = new Csrf($this->session);
        $this->owners = new OwnerRepository($this->database);
        $this->auth = new Auth($this->owners, $this->session, $this->csrf);
        $this->view = new View($config);
        $this->dashboard = new DashboardRepository($this->database);
        $this->batches = new BatchRepository($this->database);
        $this->passes = new PassRepository($this->database);
        $this->presets = new PresetRepository($this->database);
        $this->templates = new TemplateRepository($this->database);
        $this->photos = new PhotoRepository($this->database);
        $this->analytics = new AnalyticsRepository($this->database);
        $this->authentication = new AuthenticationRepository($this->database);
        $cipher = new SecretCipher($config->securityKeyPath);
        $this->localSecondFactor = new LocalSecondFactor($this->authentication, $cipher);
        $this->oidc = new OidcService($this->authentication, $cipher, $this->externalUrl);
        $this->batchNormalizer = new BatchFormNormalizer();
        $this->passNormalizer = new PassFormNormalizer();
        $this->photoStorage = new PhotoStorage($config);
        $this->backups = new BackupService($config, $this->database);
        $this->router = new Router();
        $this->registerRoutes();
    }

    public function run(Request $request): Response
    {
        $this->purgeExpiredAuthenticationSessionState();
        $ownerExists = $this->owners->exists();
        if (!$ownerExists && $request->path !== '/setup') {
            return Response::redirect('/setup');
        }
        if ($ownerExists && $request->path === '/setup') {
            return Response::redirect($this->auth->check() ? '/' : '/login');
        }
        $publicAuthenticationPaths = [
            '/login',
            '/login/oidc',
            '/login/totp',
            '/auth/oidc/continue',
            '/auth/oidc/cancel',
            '/auth/oidc/callback',
        ];
        if ($ownerExists && !$this->auth->check()
            && !in_array($request->path, $publicAuthenticationPaths, true)) {
            return Response::redirect('/login');
        }
        if ($ownerExists && $this->auth->check()
            && in_array($request->path, ['/login', '/login/oidc', '/login/totp'], true)) {
            return Response::redirect('/');
        }

        if ($request->method === 'POST' && !$this->csrf->valid($request->input('_csrf'))) {
            $this->session->flash('error', 'That form expired. Please try again.');
            return Response::redirect($this->csrfFailureRedirect($request));
        }

        return $this->router->dispatch($request) ?? $this->notFound();
    }

    private function registerRoutes(): void
    {
        $this->registerAuthenticationRoutes();
        $this->router->get('/', function (Request $request): Response {
            $materials = $this->analytics->materialOptions();
            $material = $this->effectiveAnalyticsMaterial($request, $materials);
            $selectedMaterial = $material === '' ? null : $material;
            $summary = $this->analytics->summary($selectedMaterial);
            $materialPerformance = $this->analytics->materialPerformance();
            $yieldSequence = $this->analytics->yieldSequence($selectedMaterial, null, null);
            $highestYieldBatch = $this->analytics->highestYieldBatch($selectedMaterial, null);
            $materialChartStyles = $this->materialChartStyles();

            return Response::html($this->render('dashboard', [
                'title' => 'Dashboard',
                'activeNav' => 'dashboard',
                'materials' => $materials,
                'materialOptions' => $materials,
                'selectedMaterial' => $material,
                'analyticsSummary' => $summary,
                'yieldConsistency' => $summary['yieldConsistency'] ?? 0.0,
                'highestYieldBatch' => $highestYieldBatch,
                'materialPerformance' => $materialPerformance,
                'yieldSequence' => $yieldSequence,
                'materialChartStyles' => $materialChartStyles,
                // Compatibility alias for the previous dashboard template.
                'statistics' => $summary,
                'recentBatches' => $this->dashboard->recentBatches(8, $selectedMaterial),
                'timezone' => $this->timezone(),
                'unitSystem' => $this->unitSystem(),
            ]));
        });
        $this->registerBatchRoutes();
        $this->registerPresetAndTemplateRoutes();
        $this->registerAnalyticsRoutes();
        $this->registerSettingsRoutes();
    }

    private function registerAuthenticationRoutes(): void
    {
        $this->router->get('/setup', fn (): Response => Response::html($this->render('auth/setup', [
            'title' => 'Create owner',
            'errors' => [],
            'values' => ['username' => ''],
        ], 'auth')));

        $this->router->post('/setup', function (Request $request): Response {
            $username = $request->input('username');
            $password = $request->rawInput('password');
            $errors = $this->validateOwnerSetup($username, $password, $request->rawInput('password_confirmation'));
            if ($errors !== []) {
                return Response::html($this->render('auth/setup', [
                    'title' => 'Create owner',
                    'errors' => $errors,
                    'values' => ['username' => $username],
                ], 'auth'), 422);
            }
            $this->owners->create($username, $password);
            $this->auth->signInOwner();
            $this->session->flash('success', 'Your Rosin Tracker is ready.');
            return Response::redirect('/');
        });

        $this->router->get('/login', fn (Request $request): Response => $this->loginPageResponse(
            values: ['username' => ''],
            notice: $this->query($request, 'application-url') === 'updated'
                ? 'Application URL updated. Sign in again, then register the new Redirect URI.'
                : '',
        ));

        $this->router->post('/login', function (Request $request): Response {
            if ($this->authentication->activeMethod() !== AuthenticationRepository::LOCAL) {
                return $this->loginPageResponse(
                    ['login' => 'Use the configured OpenID provider to sign in.'],
                    ['username' => ''],
                    422,
                );
            }
            $blockedFor = $this->loginBlockedFor();
            if ($blockedFor > 0) {
                return $this->loginPageResponse(
                    ['login' => 'Too many attempts. Try again in ' . $blockedFor . ' seconds.'],
                    ['username' => $request->input('username')],
                    429,
                );
            }
            $username = $request->input('username');
            if (!$this->auth->credentialsValid($username, $request->rawInput('password'))) {
                $this->recordLoginFailure();
                return $this->loginPageResponse(
                    ['login' => 'The owner name or password is incorrect.'],
                    ['username' => $username],
                    422,
                );
            }

            $summary = $this->authentication->summary();
            if (($summary['local']['totpEnabled'] ?? false) === true) {
                $this->session->regenerate();
                $this->csrf->rotate();
                $this->session->set(self::PENDING_LOCAL_LOGIN_KEY, [
                    'ownerId' => 1,
                    'issuedAt' => time(),
                    'sessionEpoch' => $this->owners->sessionEpoch(),
                ]);
                return Response::redirect('/login/totp');
            }

            $this->clearLoginFailures();
            $this->auth->signInOwner();
            return Response::redirect('/');
        });

        $this->router->get('/login/totp', function (): Response {
            if ($this->pendingLocalLogin() === null) {
                return Response::redirect('/login');
            }
            return $this->totpLoginPageResponse();
        });

        $this->router->post('/login/totp', function (Request $request): Response {
            if ($this->pendingLocalLogin() === null) {
                return Response::redirect('/login');
            }
            $blockedFor = $this->loginBlockedFor();
            if ($blockedFor > 0) {
                return $this->totpLoginPageResponse(
                    ['code' => 'Too many attempts. Try again in ' . $blockedFor . ' seconds.'],
                    429,
                );
            }
            if (!$this->localSecondFactor->verify($request->rawInput('code'))) {
                $this->recordLoginFailure();
                return $this->totpLoginPageResponse(
                    ['code' => 'The authenticator or recovery code is not valid.'],
                    422,
                );
            }

            $this->session->remove(self::PENDING_LOCAL_LOGIN_KEY);
            $this->clearLoginFailures();
            $this->auth->signInOwner();
            return Response::redirect('/');
        });

        $this->router->post('/login/oidc', function (): Response {
            try {
                $flow = $this->oidc->begin('login');
                $flow['sessionEpoch'] = $this->owners->sessionEpoch();
                $this->session->set(self::OIDC_FLOW_KEY, $flow);
                return Response::redirect('/auth/oidc/continue');
            } catch (AuthenticationException $error) {
                $this->session->flash('error', $error->getMessage());
                return Response::redirect('/login');
            }
        });

        $this->router->get('/auth/oidc/continue', function (): Response {
            $flow = $this->session->get(self::OIDC_FLOW_KEY);
            $purpose = is_array($flow) && isset($flow['purpose']) && is_string($flow['purpose'])
                ? $flow['purpose']
                : 'login';
            $fallback = $purpose === 'link' && $this->auth->check() ? '/settings' : '/login';
            $url = is_array($flow) && isset($flow['url']) && is_string($flow['url'])
                ? $flow['url']
                : '';
            $scheme = $url === '' ? '' : strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (!is_array($flow)
                || !in_array($purpose, ['link', 'login'], true)
                || !$this->issuedAtIsFresh($flow['issuedAt'] ?? null, self::OIDC_FLOW_LIFETIME)
                || ($flow['sessionEpoch'] ?? null) !== $this->owners->sessionEpoch()
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || $scheme !== 'https'
                || ($purpose === 'link' && !$this->auth->check())
                || ($purpose === 'login'
                    && $this->authentication->activeMethod() !== AuthenticationRepository::OIDC)) {
                $this->session->remove(self::OIDC_FLOW_KEY);
                $this->session->flash('error', 'The OpenID sign-in request expired. Please try again.');
                return Response::redirect($fallback);
            }

            $provider = $this->authentication->summary()['provider'] ?? null;
            $providerName = is_array($provider)
                && isset($provider['displayName'])
                && is_string($provider['displayName'])
                && trim($provider['displayName']) !== ''
                    ? trim($provider['displayName'])
                    : 'OpenID provider';

            return Response::html($this->render('auth/oidc-continue', [
                'title' => 'Continue to ' . $providerName,
                'providerName' => $providerName,
                'authorizationUrl' => $url,
                'cancelUrl' => $purpose === 'link' ? '/settings' : '/login',
            ], 'auth'));
        });

        $this->router->post('/auth/oidc/cancel', function (): Response {
            $flow = $this->session->get(self::OIDC_FLOW_KEY);
            $purpose = is_array($flow) && ($flow['purpose'] ?? null) === 'link' ? 'link' : 'login';
            $this->session->remove(self::OIDC_FLOW_KEY);
            return Response::redirect($purpose === 'link' && $this->auth->check() ? '/settings' : '/login');
        });

        $this->router->get('/auth/oidc/callback', function (Request $request): Response {
            $flow = $this->session->get(self::OIDC_FLOW_KEY);
            $this->session->remove(self::OIDC_FLOW_KEY);
            $purpose = is_array($flow) && isset($flow['purpose']) && is_string($flow['purpose'])
                ? $flow['purpose']
                : 'login';
            try {
                if (!is_array($flow)) {
                    throw new AuthenticationException('The OpenID sign-in request expired or did not match this session.');
                }
                if (($flow['sessionEpoch'] ?? null) !== $this->owners->sessionEpoch()) {
                    throw new AuthenticationException('The OpenID sign-in request was invalidated by an authentication change.');
                }
                if ($this->query($request, 'error') !== '') {
                    throw new AuthenticationException('OpenID sign-in was canceled or denied by the provider.');
                }
                $result = $this->oidc->complete(
                    $this->query($request, 'code'),
                    $this->query($request, 'state'),
                    $flow,
                );
                if ($result['purpose'] === 'link') {
                    if (!$this->auth->check()) {
                        throw new AuthenticationException('Sign in locally before linking an OpenID identity.');
                    }
                    if (!$this->authenticationChangeAuthorized()) {
                        throw new AuthenticationException(
                            'Authentication confirmation expired. Save and validate the provider again.',
                        );
                    }
                    $this->authentication->linkOidcIdentity($result);
                    $this->session->flash('success', 'OpenID identity linked. Activate it when you are ready.');
                    return Response::redirect('/settings');
                }
                if (!$this->authentication->oidcIdentityMatches($result['issuer'], $result['subject'])) {
                    throw new AuthenticationException('This OpenID identity is not linked to the owner account.');
                }
                $this->clearLoginFailures();
                $this->auth->signInOwner();
                return Response::redirect('/');
            } catch (RuntimeException $error) {
                $this->session->flash('error', $error->getMessage());
                return Response::redirect($purpose === 'link' && $this->auth->check() ? '/settings' : '/login');
            }
        });

        $this->router->post('/logout', function (): Response {
            $this->auth->logout();
            return Response::redirect('/login');
        });
    }

    private function registerBatchRoutes(): void
    {
        $this->router->get('/batches', function (Request $request): Response {
            $search = $this->query($request, 'search');
            $sort = $this->query($request, 'sort', 'date');
            if (!isset(self::BATCH_SORTS[$sort])) {
                $sort = 'date';
            }
            $materials = $this->analytics->materialOptions();
            $material = $this->query($request, 'material');
            if ($material === 'all' || !in_array($material, $materials, true)) {
                $material = '';
            }
            $pageValue = $this->query($request, 'page', '1');
            $page = ctype_digit($pageValue) ? max(1, min((int) $pageValue, 20000)) : 1;
            $perPage = 48;
            $filters = ['search' => $search, 'material' => $material, 'sort' => $sort];
            $errors = [];
            try {
                $result = $this->batches->search([
                    'query' => $search,
                    'material' => $material,
                    'sort' => self::BATCH_SORTS[$sort],
                    'limit' => $perPage,
                    'offset' => ($page - 1) * $perPage,
                ]);
            } catch (ValidationException $error) {
                $errors = $error->errors();
                $result = $this->batches->search([
                    'material' => $material,
                    'sort' => self::BATCH_SORTS[$sort],
                    'limit' => $perPage,
                    'offset' => ($page - 1) * $perPage,
                ]);
            }

            return Response::html($this->render('batches/index', [
                'title' => 'All Batches',
                'activeNav' => 'batches',
                'batches' => $result['items'],
                'batchResults' => $result,
                'filters' => $filters,
                'materials' => $materials,
                'errors' => $errors,
                'unitSystem' => $this->unitSystem(),
            ]));
        });

        $this->router->get('/new-press', fn (): Response => Response::redirect('/batches/new'));

        $this->router->get('/batches/new', function (Request $request): Response {
            $unitSystem = $this->unitSystem();
            $form = $this->emptyBatchForm($unitSystem);
            $errors = [];
            $templateId = $this->positiveQueryId($request, 'template');
            if ($templateId !== null) {
                $templateValues = $this->templates->formValues($templateId, $unitSystem);
                if ($templateValues === null) {
                    $errors['source_template_id'] = 'The selected template is no longer available.';
                } else {
                    $form = $this->applyTemplateToNewForm($form, $templateValues, $templateId);
                }
            }
            return $this->batchFormResponse('batches/new', $form, $errors, [], null, 200);
        });

        $this->router->post('/batches/new', function (Request $request): Response {
            $unitSystem = $this->unitSystem();
            $storedPhotos = [];
            try {
                $uploads = $this->photoStorage->validateUploadBag($this->photoUploadBag($request), 5);
                $submission = $this->batchNormalizer->normalizeSubmission(
                    array_replace($request->post, [
                        'pressed_at' => null,
                        'pressed_at_mode' => 'automatic',
                    ]),
                    $unitSystem,
                    $this->timezone(),
                );

                $this->database->beginTransaction();
                try {
                    $batch = $this->batches->create($submission->batch);
                    $batchId = (int) $batch['id'];
                    foreach (array_slice($submission->passes, 1) as $pass) {
                        $this->passes->create($batchId, $pass);
                    }
                    $nextPosition = $this->photos->nextPositionForBatch($batchId);
                    foreach ($uploads as $upload) {
                        $stored = $this->photoStorage->store($batchId, $upload);
                        $storedPhotos[] = $stored['storage_name'];
                        $this->photos->add($batchId, $nextPosition++, $stored);
                    }
                    if ($request->input('save_as_template') === '1') {
                        $this->templates->createFromBatch(
                            $request->input('template_name'),
                            $submission->batch,
                            $submission->passes,
                        );
                    }
                    $this->database->commit();
                } catch (Throwable $error) {
                    if ($this->database->inTransaction()) {
                        $this->database->rollBack();
                    }
                    throw $error;
                }

                $this->session->flash(
                    'success',
                    $request->input('save_as_template') === '1'
                        ? 'Batch saved and its reusable settings were added to Templates.'
                        : 'Batch saved.',
                );
                return Response::redirect('/batch/' . $batchId);
            } catch (ValidationException|PhotoUploadException $error) {
                $this->deleteStoredPhotos($storedPhotos);
                $errors = $error instanceof ValidationException
                    ? $error->errors()
                    : ['photos' => $error->getMessage()];
                return $this->batchFormResponse(
                    'batches/new',
                    $this->submittedBatchForm($request, $unitSystem, true),
                    $errors,
                    [],
                    null,
                    422,
                );
            } catch (Throwable $error) {
                $this->deleteStoredPhotos($storedPhotos);
                throw $error;
            }
        });

        $this->router->get('/batch/{id:id}', function (Request $request, string $id): Response {
            $batch = $this->batches->find((int) $id);
            if ($batch === null) {
                return $this->notFound('Batch not found');
            }
            $batch['photos'] = $this->photoUrls($batch['photos']);
            return Response::html($this->render('batches/show', [
                'title' => 'Batch #' . $id,
                'activeNav' => 'batches',
                'batch' => $batch,
                'photos' => $batch['photos'],
                'unitSystem' => $this->unitSystem(),
            ]));
        });

        $this->router->get('/batch/{batchId:id}/passes/new', function (Request $request, string $batchId): Response {
            $batch = $this->batches->find((int) $batchId);
            if ($batch === null) {
                return $this->notFound('Batch not found');
            }
            $values = $this->passes->duplicateLastFormValues((int) $batchId, $this->unitSystem());
            if ($values === null) {
                return $this->notFound('This batch has no first pass');
            }
            return $this->passFormResponse('batches/pass_new', $batch, $values, [], null, 200);
        });

        $this->router->post('/batch/{batchId:id}/passes', function (Request $request, string $batchId): Response {
            $id = (int) $batchId;
            $batch = $this->batches->find($id);
            if ($batch === null) {
                return $this->notFound('Batch not found');
            }
            try {
                $pass = $this->passes->create(
                    $id,
                    $this->passNormalizer->normalize($request->post, $this->unitSystem()),
                );
            } catch (ValidationException $error) {
                return $this->passFormResponse(
                    'batches/pass_new',
                    $batch,
                    $this->submittedPassForm($request),
                    $error->errors(),
                    null,
                    422,
                );
            }
            $this->session->flash('success', 'Pass ' . (int) $pass['position'] . ' added.');
            return Response::redirect('/batch/' . $id);
        });

        $this->router->get('/batch/{batchId:id}/passes/{passId:id}/edit', function (
            Request $request,
            string $batchId,
            string $passId,
        ): Response {
            $batch = $this->batches->find((int) $batchId);
            $pass = $this->passes->find((int) $batchId, (int) $passId);
            $data = $this->passes->data((int) $batchId, (int) $passId);
            if ($batch === null || $pass === null || $data === null) {
                return $this->notFound('Pass not found');
            }
            return $this->passFormResponse(
                'batches/pass_edit',
                $batch,
                $data->toFormValues($this->unitSystem()),
                [],
                $pass,
                200,
            );
        });

        $this->router->post('/batch/{batchId:id}/passes/{passId:id}/edit', function (
            Request $request,
            string $batchId,
            string $passId,
        ): Response {
            $id = (int) $batchId;
            $pressPassId = (int) $passId;
            $batch = $this->batches->find($id);
            $pass = $this->passes->find($id, $pressPassId);
            if ($batch === null || $pass === null) {
                return $this->notFound('Pass not found');
            }
            try {
                $updated = $this->passes->update(
                    $id,
                    $pressPassId,
                    $this->passNormalizer->normalize($request->post, $this->unitSystem()),
                );
                if ($updated === null) {
                    return $this->notFound('Pass not found');
                }
            } catch (ValidationException $error) {
                return $this->passFormResponse(
                    'batches/pass_edit',
                    $batch,
                    $this->submittedPassForm($request),
                    $error->errors(),
                    $pass,
                    422,
                );
            }
            $this->session->flash('success', 'Pass ' . (int) $pass['position'] . ' updated.');
            return Response::redirect('/batch/' . $id);
        });

        $this->router->post('/batch/{batchId:id}/passes/{passId:id}/delete', function (
            Request $request,
            string $batchId,
            string $passId,
        ): Response {
            $id = (int) $batchId;
            try {
                if (!$this->passes->delete($id, (int) $passId)) {
                    return $this->notFound('Pass not found');
                }
            } catch (ValidationException $error) {
                $this->session->flash('error', $this->firstError($error->errors()));
                return Response::redirect('/batch/' . $id);
            }
            $this->session->flash('success', 'Pass removed. Remaining passes were renumbered.');
            return Response::redirect('/batch/' . $id);
        });

        $this->router->get('/batch/{id:id}/edit', function (Request $request, string $id): Response {
            $batchId = (int) $id;
            $batch = $this->batches->find($batchId);
            if ($batch === null) {
                return $this->notFound('Batch not found');
            }
            $unitSystem = $this->unitSystem();
            $form = $this->batches->formValues($batchId, $unitSystem, $this->timezone());
            if ($form === null) {
                return $this->notFound('Batch not found');
            }
            return $this->batchFormResponse(
                'batches/edit',
                $form,
                [],
                $this->photoUrls($batch['photos']),
                $batch,
                200,
            );
        });

        $this->router->post('/batch/{id:id}/edit', function (Request $request, string $id): Response {
            $batchId = (int) $id;
            $batch = $this->batches->find($batchId);
            if ($batch === null) {
                return $this->notFound('Batch not found');
            }

            $unitSystem = $this->unitSystem();
            $storedPhotos = [];
            $removedStorageNames = [];
            try {
                $removals = $this->selectedPhotoRemovals($request, $batch);
                $remaining = count($batch['photos']) - count($removals);
                $uploads = $this->photoStorage->validateUploadBag(
                    $this->photoUploadBag($request),
                    5 - $remaining,
                );
                $data = $this->batchNormalizer->normalize(
                    $request->post,
                    $unitSystem,
                    $this->timezone(),
                    $batch['bags'] !== [],
                );

                $this->database->beginTransaction();
                try {
                    if ($this->batches->update($batchId, $data) === null) {
                        throw new RuntimeException('The batch is no longer available.');
                    }
                    foreach ($removals as $photoId) {
                        $removed = $this->photos->remove($photoId);
                        if ($removed !== null) {
                            $removedStorageNames[] = (string) $removed['storage_name'];
                        }
                    }
                    $nextPosition = $this->photos->nextPositionForBatch($batchId);
                    foreach ($uploads as $upload) {
                        $stored = $this->photoStorage->store($batchId, $upload);
                        $storedPhotos[] = $stored['storage_name'];
                        $this->photos->add($batchId, $nextPosition++, $stored);
                    }
                    $this->database->commit();
                } catch (Throwable $error) {
                    if ($this->database->inTransaction()) {
                        $this->database->rollBack();
                    }
                    throw $error;
                }

                $this->deleteStoredPhotos($removedStorageNames);
                $this->session->flash('success', 'Batch updated.');
                return Response::redirect('/batch/' . $batchId);
            } catch (ValidationException|PhotoUploadException $error) {
                $this->deleteStoredPhotos($storedPhotos);
                $errors = $error instanceof ValidationException
                    ? $error->errors()
                    : ['photos' => $error->getMessage()];
                return $this->batchFormResponse(
                    'batches/edit',
                    $this->submittedBatchForm($request, $unitSystem),
                    $errors,
                    $this->photoUrls($batch['photos']),
                    $batch,
                    422,
                );
            } catch (Throwable $error) {
                $this->deleteStoredPhotos($storedPhotos);
                throw $error;
            }
        });

        $this->router->post('/batch/{id:id}/delete', function (Request $request, string $id): Response {
            $batchId = (int) $id;
            $batch = $this->batches->find($batchId);
            if ($batch === null) {
                return $this->notFound('Batch not found');
            }
            $this->database->beginTransaction();
            try {
                if (!$this->batches->delete($batchId)) {
                    throw new RuntimeException('The batch could not be deleted.');
                }
                $this->database->commit();
            } catch (Throwable $error) {
                if ($this->database->inTransaction()) {
                    $this->database->rollBack();
                }
                throw $error;
            }
            $this->deleteStoredPhotos(array_map(
                static fn (array $photo): string => (string) $photo['storageName'],
                $batch['photos'],
            ));
            $this->session->flash('success', 'Batch #' . $batchId . ' was deleted.');
            return Response::redirect('/batches');
        });

        $this->router->post('/batch/{id:id}/template', function (Request $request, string $id): Response {
            $batchId = (int) $id;
            $data = $this->batches->data($batchId);
            if (!$data instanceof BatchData) {
                return $this->notFound('Batch not found');
            }
            try {
                $passes = array_map(
                    static fn (array $pass): PassData => new PassData(
                        temperatureC: (float) $pass['temperatureC'],
                        pressureBar: $pass['pressureBar'] === null ? null : (float) $pass['pressureBar'],
                        preheatSeconds: $pass['preheatSeconds'] === null ? null : (int) $pass['preheatSeconds'],
                        pressDurationSeconds: $pass['pressDurationSeconds'] === null
                            ? null
                            : (int) $pass['pressDurationSeconds'],
                    ),
                    $this->passes->listForBatch($batchId),
                );
                $this->templates->createFromBatch(
                    $request->input('name', $request->input('template_name')),
                    $data,
                    $passes,
                );
            } catch (ValidationException $error) {
                $this->session->flash('error', $this->firstError($error->errors()));
                return Response::redirect('/batch/' . $batchId);
            }
            $this->session->flash('success', 'Reusable settings saved as a template.');
            return Response::redirect('/presets');
        });

        $this->router->get('/photos/{id:id}', function (Request $request, string $id): Response {
            $photo = $this->photos->find((int) $id);
            if ($photo === null) {
                return $this->notFound('Photograph not found');
            }
            $path = $this->photoStorage->absolutePath((string) $photo['storage_name']);
            if ($path === null) {
                return $this->notFound('Photograph not found');
            }
            $extension = match ((string) $photo['mime_type']) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'bin',
            };
            return Response::file($path, [
                'Content-Type' => (string) $photo['mime_type'],
                'Content-Disposition' => 'inline; filename="batch-photo-' . (int) $photo['id'] . '.' . $extension . '"',
                'Cache-Control' => 'private, no-store',
            ]);
        });

        $this->router->post('/photos/{id:id}/delete', function (Request $request, string $id): Response {
            $photo = $this->photos->find((int) $id);
            if ($photo === null) {
                return $this->notFound('Photograph not found');
            }
            $batchId = (int) $photo['batch_id'];
            $removed = $this->photos->remove((int) $id);
            if ($removed !== null) {
                $this->photoStorage->delete((string) $removed['storage_name']);
            }
            $this->session->flash('success', 'Photograph removed.');
            return Response::redirect('/batch/' . $batchId . '/edit');
        });
    }

    private function registerPresetAndTemplateRoutes(): void
    {
        $this->router->get('/presets', fn (): Response => $this->presetPageResponse());

        $this->router->post('/presets', function (Request $request): Response {
            try {
                $field = $request->input('field_key');
                $value = $this->presetValue($field, $request->input('value'), $request);
                $this->presets->create(
                    $field,
                    $this->presetLabel($field, $this->presetSubmittedName($request), $value),
                    $value,
                    $this->sortOrder($request->input('sort_order')),
                );
            } catch (ValidationException $error) {
                $this->session->flash('error', $this->firstError($error->errors()));
                return Response::redirect('/presets');
            }
            $this->session->flash('success', 'Preset added.');
            return Response::redirect('/presets');
        });

        $this->router->post('/presets/{id:id}/edit', function (Request $request, string $id): Response {
            $presetId = (int) $id;
            $existingPreset = $this->presets->find($presetId);
            if ($existingPreset === null) {
                return $this->notFound('Preset not found');
            }
            try {
                $field = isset($existingPreset['fieldKey']) && is_string($existingPreset['fieldKey'])
                    ? $existingPreset['fieldKey']
                    : '';
                if ($request->input('field_key') !== $field) {
                    throw new ValidationException(['field_key' => 'That preset type cannot be changed.']);
                }
                $value = $this->presetValue(
                    $field,
                    $request->input('value'),
                    $request,
                    $existingPreset['value'] ?? null,
                );
                $this->presets->update(
                    $presetId,
                    $field,
                    $this->presetLabel($field, $this->presetSubmittedName($request), $value),
                    $value,
                    $this->sortOrder($request->input('sort_order')),
                );
            } catch (ValidationException $error) {
                $this->session->flash('error', $this->firstError($error->errors()));
                return Response::redirect('/presets');
            }
            $this->session->flash('success', 'Preset updated.');
            return Response::redirect('/presets');
        });

        $this->router->post('/presets/{id:id}/delete', function (Request $request, string $id): Response {
            if (!$this->presets->delete((int) $id)) {
                return $this->notFound('Preset not found');
            }
            $this->session->flash('success', 'Preset deleted. Existing batches were not changed.');
            return Response::redirect('/presets');
        });

        $this->router->post('/templates', function (Request $request): Response {
            try {
                $unitSystem = $this->unitSystem();
                $data = $this->batchNormalizer->normalizeTemplate(
                    $request->post,
                    $unitSystem,
                    $this->timezone(),
                );
                $data = $this->templateWithSubmittedStrains($data, $request, $unitSystem);
                $this->templates->create(
                    $request->input('name', $request->input('template_name')),
                    $data,
                );
            } catch (ValidationException $error) {
                $this->session->flash('error', $this->firstError($error->errors()));
                return Response::redirect('/presets');
            }
            $this->session->flash('success', 'Template added.');
            return Response::redirect('/presets');
        });

        $this->router->post('/templates/{id:id}/edit', function (Request $request, string $id): Response {
            $templateId = (int) $id;
            $existingTemplate = $this->templates->data($templateId);
            if ($existingTemplate === null) {
                return $this->notFound('Template not found');
            }
            try {
                $unitSystem = $this->unitSystem();
                $data = $this->batchNormalizer->normalizeTemplate(
                    $request->post,
                    $unitSystem,
                    $this->timezone(),
                    $existingTemplate->bags !== [],
                );
                $data = $this->templateWithSubmittedStrains(
                    $data,
                    $request,
                    $unitSystem,
                    $existingTemplate,
                );
                $this->templates->update(
                    $templateId,
                    $request->input('name', $request->input('template_name')),
                    $data,
                );
            } catch (ValidationException $error) {
                $this->session->flash('error', $this->firstError($error->errors()));
                return Response::redirect('/presets');
            }
            $this->session->flash('success', 'Template updated. Existing batches were not changed.');
            return Response::redirect('/presets');
        });

        $this->router->post('/templates/{id:id}/delete', function (Request $request, string $id): Response {
            if (!$this->templates->delete((int) $id)) {
                return $this->notFound('Template not found');
            }
            $this->session->flash('success', 'Template deleted. Existing batches were not changed.');
            return Response::redirect('/presets');
        });
    }

    private function registerAnalyticsRoutes(): void
    {
        $this->router->get('/analytics', function (Request $request): Response {
            $materials = $this->analytics->materialOptions();
            $material = $this->effectiveAnalyticsMaterial($request, $materials);
            $selectedMaterial = $material === '' ? null : $material;
            $strainOptions = $this->analytics->strainOptions($selectedMaterial);
            $strain = $this->analyticsOption($this->query($request, 'strain'), $strainOptions);
            $selectedStrain = $strain === '' ? null : $strain;
            $summary = $this->analytics->summary($selectedMaterial, $selectedStrain);
            $materialPerformance = $this->analytics->materialPerformance($selectedStrain);
            $strainPerformance = $this->analytics->strainPerformance($selectedMaterial);
            $blendPerformance = $this->analytics->blendPerformance($selectedMaterial);
            $temperatureYield = $this->analytics->temperatureYield($selectedMaterial, $selectedStrain);
            $yieldSequence = $this->analytics->yieldSequence($selectedMaterial, $selectedStrain, null);
            $batchComparison = $this->analytics->batchComparison($selectedMaterial, $selectedStrain, 100);
            $materialChartStyles = $this->materialChartStyles();
            $analytics = [
                'materials' => $materials,
                'materialOptions' => $materials,
                'selectedMaterial' => $material,
                'strainOptions' => $strainOptions,
                'strains' => $strainOptions,
                'selectedStrain' => $strain,
                'summary' => $summary,
                'materialPerformance' => $materialPerformance,
                'strainPerformance' => $strainPerformance,
                'blendPerformance' => $blendPerformance,
                'temperatureYield' => $temperatureYield,
                'temperaturePoints' => $temperatureYield['points'],
                'temperatureBands' => $temperatureYield['bands'],
                'yieldSequence' => $yieldSequence,
                'batchComparison' => $batchComparison,
                'materialChartStyles' => $materialChartStyles,
                // Compatibility aliases for callers transitioning from the old model.
                'temperatureCorrelation' => $temperatureYield['bands'],
                'yieldTrend' => $yieldSequence,
                'environmentTrend' => [],
            ];

            return Response::html($this->render('analytics', [
                'title' => 'Analytics',
                'activeNav' => 'analytics',
                'statistics' => $summary,
                'analyticsSummary' => $summary,
                'analytics' => $analytics,
                'materials' => $materials,
                'materialOptions' => $materials,
                'selectedMaterial' => $material,
                'strainOptions' => $strainOptions,
                'strains' => $strainOptions,
                'selectedStrain' => $strain,
                'materialPerformance' => $materialPerformance,
                'strainPerformance' => $strainPerformance,
                'blendPerformance' => $blendPerformance,
                'temperatureYield' => $temperatureYield,
                'temperaturePoints' => $temperatureYield['points'],
                'temperatureBands' => $temperatureYield['bands'],
                'yieldSequence' => $yieldSequence,
                'batchComparison' => $batchComparison,
                'materialChartStyles' => $materialChartStyles,
                'temperatureCorrelation' => $temperatureYield['bands'],
                'unitSystem' => $this->unitSystem(),
                'timezone' => $this->timezone(),
            ]));
        });
    }

    private function registerSettingsRoutes(): void
    {
        $this->router->get('/settings', fn (): Response => $this->settingsPageResponse());

        $saveUnits = function (Request $request): Response {
            try {
                $unitSystem = Units::normalizeSystem($request->input('unit_system'));
            } catch (InvalidArgumentException) {
                $this->session->flash('error', 'Choose metric or imperial units.');
                return Response::redirect('/settings');
            }
            $this->settings->set('unit_system', $unitSystem);
            $this->session->flash('success', 'Unit system updated. Stored measurements were not changed.');
            return Response::redirect('/settings');
        };
        $this->router->post('/settings/units', $saveUnits);

        $this->router->post('/settings/accent', function (Request $request): Response {
            $accent = strtolower($request->input('accent'));
            if (!in_array($accent, ['blue', 'green', 'red'], true)) {
                $this->session->flash('error', 'Choose blue, green, or red.');
                return Response::redirect('/settings');
            }
            $this->settings->set('accent', $accent);
            $this->session->flash('success', ucfirst($accent) . ' accent selected.');
            return Response::redirect('/settings');
        });

        $this->router->post('/settings/analytics-material', function (Request $request): Response {
            $materials = $this->analytics->materialOptions();
            $submitted = $request->input('analytics_default_material');
            $material = $this->analyticsOption($submitted, $materials);
            if ($submitted !== '' && strcasecmp($submitted, 'all') !== 0 && $material === '') {
                $this->session->flash('error', 'Choose a saved material or All materials.');
                return Response::redirect('/settings');
            }

            $this->settings->set('analytics_default_material', $material);
            $this->session->flash(
                'success',
                $material === ''
                    ? 'Dashboard and Analytics now open with all materials.'
                    : 'Dashboard and Analytics now open with ' . $material . '.',
            );
            return Response::redirect('/settings');
        });

        $this->router->post('/settings/application-url', function (Request $request): Response {
            if ($this->config->externalUrlManaged) {
                $this->session->flash('error', 'The application URL is managed by the server configuration.');
                return Response::redirect('/settings');
            }

            try {
                $externalUrl = ExternalUrl::fromString($request->rawInput('application_url'));
                $this->authorizeAuthenticationChange($request);
                if ($externalUrl->value() === $this->externalUrl) {
                    $this->session->flash('success', 'The application URL is already up to date.');
                    return Response::redirect('/settings');
                }

                $this->database->beginTransaction();
                try {
                    $this->settings->set('external_url', $externalUrl->value());
                    $this->owners->revokeSessions();
                    $this->database->commit();
                } catch (Throwable $error) {
                    if ($this->database->inTransaction()) {
                        $this->database->rollBack();
                    }
                    throw $error;
                }

                $this->auth->logout();
                return Response::redirect(
                    $externalUrl->value() . '/login?application-url=updated',
                );
            } catch (InvalidArgumentException|AuthenticationException $error) {
                return $this->settingsPageResponse(
                    ['application_url' => $error->getMessage()],
                    ['application_url' => $request->rawInput('application_url')],
                    422,
                );
            }
        });

        $this->router->post('/settings/authentication/totp/start', function (Request $request): Response {
            try {
                if (($this->authentication->summary()['local']['totpEnabled'] ?? false) === true) {
                    throw new RuntimeException('Disable the existing authenticator before enrolling another one.');
                }
                $this->authorizeAuthenticationChange($request);
                $owner = $this->auth->owner();
                $enrollment = $this->localSecondFactor->beginEnrollment(
                    is_array($owner) ? (string) $owner['username'] : 'Owner',
                );
                $this->session->set(self::TOTP_ENROLLMENT_KEY, $enrollment);
                return Response::redirect('/settings/authentication/totp/setup');
            } catch (RuntimeException $error) {
                $this->session->flash('error', $error->getMessage());
                return Response::redirect('/settings');
            }
        });

        $this->router->get('/settings/authentication/totp/setup', function (): Response {
            $enrollment = $this->totpEnrollment();
            if ($enrollment === null) {
                return Response::redirect('/settings');
            }
            return Response::html($this->render('auth/totp_setup', [
                'title' => 'Set up authenticator',
                'errors' => [],
                'enrollment' => $enrollment,
                'wideAuth' => true,
            ], 'auth'));
        });

        $this->router->post('/settings/authentication/totp/setup', function (Request $request): Response {
            $enrollment = $this->totpEnrollment();
            if ($enrollment === null) {
                $this->session->flash('error', 'Authenticator setup is missing. Start again.');
                return Response::redirect('/settings');
            }
            try {
                $codes = $this->localSecondFactor->confirmEnrollment(
                    $enrollment,
                    $request->rawInput('code'),
                );
                $this->session->remove(self::TOTP_ENROLLMENT_KEY);
                $this->session->set(self::RECOVERY_CODES_KEY, [
                    'codes' => $codes,
                    'issuedAt' => time(),
                ]);
                $this->auth->signInOwner();
                return Response::redirect('/settings/authentication/recovery-codes');
            } catch (RuntimeException $error) {
                return Response::html($this->render('auth/totp_setup', [
                    'title' => 'Set up authenticator',
                    'errors' => ['code' => $error->getMessage()],
                    'enrollment' => $enrollment,
                    'wideAuth' => true,
                ], 'auth'), 422);
            }
        });

        $this->router->post('/settings/authentication/totp/cancel', function (): Response {
            $this->session->remove(self::TOTP_ENROLLMENT_KEY);
            return Response::redirect('/settings');
        });

        $this->router->get('/settings/authentication/recovery-codes', function (): Response {
            $codes = $this->oneTimeRecoveryCodes();
            $this->session->remove(self::RECOVERY_CODES_KEY);
            if ($codes === []) {
                return Response::redirect('/settings');
            }
            $recoveryCodes = array_values(array_filter(
                $codes,
                static fn (mixed $code): bool => is_string($code) && $code !== '',
            ));
            if ($recoveryCodes === []) {
                return Response::redirect('/settings');
            }
            return Response::html($this->render('auth/recovery_codes', [
                'title' => 'Save recovery codes',
                'recoveryCodes' => $recoveryCodes,
                'wideAuth' => true,
            ], 'auth'));
        });

        $this->router->post('/settings/authentication/totp/disable', function (Request $request): Response {
            if ($this->authentication->activeMethod() !== AuthenticationRepository::LOCAL) {
                $this->session->flash('error', 'Switch to local sign-in before changing its authenticator.');
                return Response::redirect('/settings');
            }
            $blockedFor = $this->authentication->sensitiveOperationBlockedFor();
            if ($blockedFor > 0) {
                $this->session->flash(
                    'error',
                    'Too many authentication attempts. Try again in ' . $blockedFor . ' seconds.',
                );
                return Response::redirect('/settings');
            }
            if (!$this->localSecondFactor->verify($request->rawInput('code'))) {
                $this->authentication->recordSensitiveFailure();
                $this->session->flash('error', 'Enter a current authenticator or recovery code.');
                return Response::redirect('/settings');
            }
            $this->localSecondFactor->disable();
            $this->authentication->clearSensitiveFailures();
            $this->auth->signInOwner();
            $this->session->flash('success', 'Two-factor authentication disabled.');
            return Response::redirect('/settings');
        });

        $this->router->post('/settings/authentication/totp/recovery-codes', function (Request $request): Response {
            if ($this->authentication->activeMethod() !== AuthenticationRepository::LOCAL) {
                $this->session->flash('error', 'Switch to local sign-in before changing its recovery codes.');
                return Response::redirect('/settings');
            }
            $blockedFor = $this->authentication->sensitiveOperationBlockedFor();
            if ($blockedFor > 0) {
                $this->session->flash(
                    'error',
                    'Too many authentication attempts. Try again in ' . $blockedFor . ' seconds.',
                );
                return Response::redirect('/settings');
            }
            try {
                $codes = $this->localSecondFactor->regenerateRecoveryCodes($request->rawInput('code'));
                $this->authentication->clearSensitiveFailures();
                $this->session->set(self::RECOVERY_CODES_KEY, [
                    'codes' => $codes,
                    'issuedAt' => time(),
                ]);
                $this->auth->signInOwner();
                return Response::redirect('/settings/authentication/recovery-codes');
            } catch (AuthenticationException $error) {
                $this->authentication->recordSensitiveFailure();
                $this->session->flash('error', $error->getMessage());
                return Response::redirect('/settings');
            }
        });

        $this->router->post('/settings/authentication/oidc', function (Request $request): Response {
            try {
                $this->authorizeAuthenticationChange($request);
                $this->oidc->saveAndValidate($request->post);
                $this->session->flash('success', 'OpenID provider validated and saved. Link the owner identity next.');
                return Response::redirect('/settings');
            } catch (AuthenticationException $error) {
                return $this->settingsPageResponse(
                    ['oidc' => $error->getMessage()],
                    $request->post,
                    422,
                );
            }
        });

        $this->router->post('/settings/authentication/oidc/link', function (): Response {
            try {
                if (!$this->authenticationChangeAuthorized()) {
                    throw new AuthenticationException(
                        'Confirm the local password in the provider configuration before linking.',
                    );
                }
                $summary = $this->authentication->summary();
                if (($summary['activeMethod'] ?? AuthenticationRepository::LOCAL) !== AuthenticationRepository::LOCAL
                    || !is_array($summary['provider'] ?? null)
                    || ($summary['provider']['linked'] ?? false) === true) {
                    throw new AuthenticationException(
                        'Linking is available only for an unlinked provider while local sign-in is active.',
                    );
                }
                $flow = $this->oidc->begin('link');
                $flow['sessionEpoch'] = $this->owners->sessionEpoch();
                $this->session->set(self::OIDC_FLOW_KEY, $flow);
                return Response::redirect('/auth/oidc/continue');
            } catch (AuthenticationException $error) {
                $this->session->flash('error', $error->getMessage());
                return Response::redirect('/settings');
            }
        });

        $this->router->post('/settings/authentication/oidc/activate', function (): Response {
            if (!$this->authenticationChangeAuthorized()) {
                $this->session->flash(
                    'error',
                    'Confirm the local password in the provider configuration before activation.',
                );
                return Response::redirect('/settings');
            }
            if (!$this->oidc->externalUrlIsHttps()) {
                $this->session->flash('error', 'Configure an HTTPS external URL before activating OpenID Connect.');
                return Response::redirect('/settings');
            }
            $provider = $this->authentication->summary()['provider'];
            if (!is_array($provider) || ($provider['linked'] ?? false) !== true) {
                $this->session->flash('error', 'Validate the provider and link the owner identity first.');
                return Response::redirect('/settings');
            }
            $this->authentication->activateOidc();
            $this->auth->logout();
            return Response::redirect('/login');
        });

        $this->router->post('/settings/authentication/local', function (): Response {
            $this->authentication->forceLocal();
            $this->auth->logout();
            return Response::redirect('/login');
        });

        $this->router->post('/settings/authentication/oidc/delete', function (): Response {
            if (!$this->authenticationChangeAuthorized()) {
                $this->session->flash(
                    'error',
                    'Confirm the local password in the provider configuration before removing it.',
                );
                return Response::redirect('/settings');
            }
            $this->authentication->removeOidc();
            $this->auth->logout();
            return Response::redirect('/login');
        });

        $this->router->post('/settings', function (Request $request): Response {
            $errors = [];
            try {
                $unitSystem = Units::normalizeSystem($request->input('unit_system'));
            } catch (InvalidArgumentException) {
                $unitSystem = $this->unitSystem();
                $errors['unit_system'] = 'Choose metric or imperial units.';
            }
            $timezone = $request->input('timezone', $this->timezone());
            try {
                if (strlen($timezone) > 80) {
                    throw new InvalidArgumentException('Timezone too long.');
                }
                $timezone = (new DateTimeZone($timezone))->getName();
            } catch (Throwable) {
                $errors['timezone'] = 'Choose a valid timezone.';
            }
            if ($errors !== []) {
                return $this->settingsPageResponse($errors, $request->post, 422);
            }

            $this->database->beginTransaction();
            try {
                $this->settings->set('unit_system', $unitSystem);
                $this->settings->set('timezone', $timezone);
                $this->database->commit();
            } catch (Throwable $error) {
                if ($this->database->inTransaction()) {
                    $this->database->rollBack();
                }
                throw $error;
            }
            $this->session->flash('success', 'Settings saved.');
            return Response::redirect('/settings');
        });

        $this->router->post('/settings/password', function (Request $request): Response {
            if ($this->authentication->activeMethod() !== AuthenticationRepository::LOCAL) {
                $this->session->flash('error', 'Switch to local authentication before changing the local password.');
                return Response::redirect('/settings');
            }
            $blockedFor = $this->authentication->sensitiveOperationBlockedFor();
            if ($blockedFor > 0) {
                $this->session->flash(
                    'error',
                    'Too many authentication attempts. Try again in ' . $blockedFor . ' seconds.',
                );
                return Response::redirect('/settings');
            }
            $owner = $this->auth->owner();
            $record = is_array($owner)
                ? $this->owners->findByUsername((string) $owner['username'])
                : null;
            $currentPassword = $request->rawInput('current_password');
            $newPassword = $request->rawInput('new_password', $request->rawInput('password'));
            $confirmation = $request->rawInput(
                'new_password_confirmation',
                $request->rawInput('password_confirmation'),
            );
            $errors = [];
            if ($record === null || !password_verify($currentPassword, $record['password_hash'])) {
                $errors['current_password'] = 'The current password is incorrect.';
                $this->authentication->recordSensitiveFailure();
            }
            $errors = array_replace($errors, PasswordPolicy::validateWithConfirmation(
                $newPassword,
                $confirmation,
                'new_password',
                'new_password_confirmation',
            ));
            if ($errors !== []) {
                $this->session->flash('error', $this->firstError($errors));
                return Response::redirect('/settings');
            }

            $this->owners->updatePassword((int) $record['id'], $newPassword);
            $this->authentication->clearSensitiveFailures();
            $this->auth->signInOwner();
            $this->session->flash('success', 'Password changed.');
            return Response::redirect('/settings');
        });

        $this->router->post('/settings/backups', function (): Response {
            $backup = $this->backups->create();
            $this->session->flash('success', 'Backup created: ' . $backup['filename']);
            return Response::redirect('/settings');
        });

        $this->router->get('/settings/backups/{filename}', function (Request $request, string $filename): Response {
            $path = $this->backups->find($filename);
            if ($path === null) {
                return $this->notFound('Backup not found');
            }
            return Response::file($path, [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'private, no-store',
            ]);
        });
    }

    /** @param array<string, string> $errors @param array<string, mixed> $values */
    private function presetPageResponse(array $errors = [], array $values = [], int $status = 200): Response
    {
        $unitSystem = $this->unitSystem();
        $groups = $this->displayPresetGroups($unitSystem);
        $allPresets = [];
        foreach ($groups as $group) {
            array_push($allPresets, ...$group);
        }
        return Response::html($this->render('presets/index', [
            'title' => 'Presets',
            'activeNav' => 'presets',
            'presets' => $allPresets,
            'presetGroups' => $groups,
            'templates' => $this->templates->allForForms($unitSystem),
            'presetFields' => self::PRESET_FIELDS,
            'materialChartStyles' => $this->materialChartStyles(),
            'materialChartColors' => self::MATERIAL_CHART_COLORS,
            'materialChartMarkers' => self::MATERIAL_CHART_MARKERS,
            'errors' => $errors,
            'values' => $values,
            'unitSystem' => $unitSystem,
        ]), $status);
    }

    /** @param array<string, string> $errors @param array<string, mixed> $values */
    private function settingsPageResponse(array $errors = [], array $values = [], int $status = 200): Response
    {
        $unitSystem = isset($values['unit_system']) && is_scalar($values['unit_system'])
            ? (string) $values['unit_system']
            : $this->unitSystem();
        $timezone = isset($values['timezone']) && is_scalar($values['timezone'])
            ? (string) $values['timezone']
            : $this->timezone();
        $accent = $this->accent();
        $analyticsMaterials = $this->analytics->materialOptions();
        $analyticsDefaultMaterial = $this->analyticsOption(
            $this->settings->get('analytics_default_material'),
            $analyticsMaterials,
        );
        $authentication = $this->authentication->summary();
        $authentication['callbackUrl'] = $this->oidc->callbackUrl();
        $authentication['composerReady'] = $this->oidc->composerReady();
        $authentication['httpsReady'] = $this->oidc->externalUrlIsHttps();
        $applicationUrl = isset($values['application_url']) && is_scalar($values['application_url'])
            ? (string) $values['application_url']
            : $this->externalUrl;
        $authenticationValues = $values;
        foreach (['client_secret', 'password', 'current_password', 'current_code', 'new_password', 'password_confirmation', 'code'] as $secretField) {
            unset($authenticationValues[$secretField]);
        }
        return Response::html($this->render('settings', [
            'title' => 'Settings',
            'activeNav' => 'settings',
            'settings' => [
                'unit_system' => $unitSystem,
                'timezone' => $timezone,
                'accent' => $accent,
                'analytics_default_material' => $analyticsDefaultMaterial,
            ],
            'unitSystem' => $unitSystem,
            'timezone' => $timezone,
            'accent' => $accent,
            'analyticsMaterialOptions' => $analyticsMaterials,
            'analyticsDefaultMaterial' => $analyticsDefaultMaterial,
            'timezones' => DateTimeZone::listIdentifiers(),
            'backups' => $this->backups->available(),
            'restoreEnabled' => false,
            'errors' => $errors,
            'values' => $authenticationValues,
            'authentication' => $authentication,
            'authenticationValues' => $authenticationValues,
            'authenticationErrors' => $errors,
            'authenticationChangeAuthorized' => $this->authenticationChangeAuthorized(),
            'externalUrl' => $this->externalUrl,
            'applicationUrl' => $applicationUrl,
            'applicationUrlManaged' => $this->config->externalUrlManaged,
        ]), $status);
    }

    /** @param array<string, string> $errors @param array<string, mixed> $values */
    private function loginPageResponse(
        array $errors = [],
        array $values = [],
        int $status = 200,
        string $notice = '',
    ): Response
    {
        $authentication = $this->authentication->summary();
        $authentication['composerReady'] = $this->oidc->composerReady();
        return Response::html($this->render('auth/login', [
            'title' => 'Sign in',
            'errors' => $errors,
            'values' => $values,
            'blockedFor' => $this->loginBlockedFor(),
            'authentication' => $authentication,
            'notice' => $notice,
        ], 'auth'), $status);
    }

    /** @param array<string, string> $errors */
    private function totpLoginPageResponse(array $errors = [], int $status = 200): Response
    {
        return Response::html($this->render('auth/totp', [
            'title' => 'Verify sign in',
            'errors' => $errors,
            'blockedFor' => $this->loginBlockedFor(),
        ], 'auth'), $status);
    }

    /** @return array{ownerId:int,issuedAt:int,sessionEpoch:int}|null */
    private function pendingLocalLogin(): ?array
    {
        $pending = $this->session->get(self::PENDING_LOCAL_LOGIN_KEY);
        $valid = is_array($pending)
            && ($pending['ownerId'] ?? null) === 1
            && isset($pending['issuedAt'])
            && is_int($pending['issuedAt'])
            && $pending['issuedAt'] >= time() - self::PENDING_LOGIN_LIFETIME
            && $pending['issuedAt'] <= time() + 60
            && isset($pending['sessionEpoch'])
            && is_int($pending['sessionEpoch'])
            && $pending['sessionEpoch'] === $this->owners->sessionEpoch()
            && $this->authentication->activeMethod() === AuthenticationRepository::LOCAL
            && ($this->authentication->summary()['local']['totpEnabled'] ?? false) === true;
        if (!$valid) {
            $this->session->remove(self::PENDING_LOCAL_LOGIN_KEY);
            return null;
        }
        return [
            'ownerId' => 1,
            'issuedAt' => $pending['issuedAt'],
            'sessionEpoch' => $pending['sessionEpoch'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function totpEnrollment(): ?array
    {
        $pending = $this->session->get(self::TOTP_ENROLLMENT_KEY);
        if (!is_array($pending)
            || !$this->issuedAtIsFresh($pending['issuedAt'] ?? null, self::TOTP_ENROLLMENT_LIFETIME)) {
            $this->session->remove(self::TOTP_ENROLLMENT_KEY);
            return null;
        }
        return $pending;
    }

    /** @return list<string> */
    private function oneTimeRecoveryCodes(): array
    {
        $pending = $this->session->get(self::RECOVERY_CODES_KEY);
        if (!is_array($pending)
            || !$this->issuedAtIsFresh($pending['issuedAt'] ?? null, self::RECOVERY_CODES_LIFETIME)
            || !is_array($pending['codes'] ?? null)) {
            return [];
        }
        return array_values(array_filter(
            $pending['codes'],
            static fn (mixed $code): bool => is_string($code) && $code !== '',
        ));
    }

    private function authorizeAuthenticationChange(Request $request): void
    {
        $blockedFor = $this->authentication->sensitiveOperationBlockedFor();
        if ($blockedFor > 0) {
            throw new AuthenticationException(
                'Too many authentication attempts. Try again in ' . $blockedFor . ' seconds.',
            );
        }
        if ($this->authentication->activeMethod() !== AuthenticationRepository::LOCAL) {
            throw new AuthenticationException('Switch to local sign-in before changing authentication settings.');
        }
        $owner = $this->auth->owner();
        if (!is_array($owner)
            || !$this->auth->credentialsValid(
                (string) $owner['username'],
                $request->rawInput('current_password'),
            )) {
            $this->authentication->recordSensitiveFailure();
            throw new AuthenticationException('Enter the current local password to confirm this change.');
        }
        if (($this->authentication->summary()['local']['totpEnabled'] ?? false) === true
            && !$this->localSecondFactor->verify($request->rawInput('current_code'))) {
            $this->authentication->recordSensitiveFailure();
            throw new AuthenticationException('Enter a current authenticator or recovery code to confirm this change.');
        }
        $this->authentication->clearSensitiveFailures();
        $this->session->set(self::AUTHENTICATION_CHANGE_KEY, [
            'issuedAt' => time(),
            'sessionEpoch' => $this->owners->sessionEpoch(),
        ]);
    }

    private function authenticationChangeAuthorized(): bool
    {
        $authorization = $this->session->get(self::AUTHENTICATION_CHANGE_KEY);
        $valid = is_array($authorization)
            && $this->issuedAtIsFresh($authorization['issuedAt'] ?? null, self::OIDC_FLOW_LIFETIME)
            && ($authorization['sessionEpoch'] ?? null) === $this->owners->sessionEpoch()
            && $this->authentication->activeMethod() === AuthenticationRepository::LOCAL;
        if (!$valid) {
            $this->session->remove(self::AUTHENTICATION_CHANGE_KEY);
        }
        return $valid;
    }

    private function purgeExpiredAuthenticationSessionState(): void
    {
        $this->totpEnrollment();
        $recovery = $this->session->get(self::RECOVERY_CODES_KEY);
        if (!is_array($recovery)
            || !$this->issuedAtIsFresh($recovery['issuedAt'] ?? null, self::RECOVERY_CODES_LIFETIME)) {
            $this->session->remove(self::RECOVERY_CODES_KEY);
        }
        $flow = $this->session->get(self::OIDC_FLOW_KEY);
        if (!is_array($flow)
            || !$this->issuedAtIsFresh($flow['issuedAt'] ?? null, self::OIDC_FLOW_LIFETIME)) {
            $this->session->remove(self::OIDC_FLOW_KEY);
        }
        $this->authenticationChangeAuthorized();
    }

    private function issuedAtIsFresh(mixed $issuedAt, int $lifetime): bool
    {
        return is_int($issuedAt)
            && $issuedAt >= time() - $lifetime
            && $issuedAt <= time() + 60;
    }

    /**
     * @param array<string, mixed> $form
     * @param array<string, string> $errors
     * @param list<array<string, mixed>> $photos
     * @param array<string, mixed>|null $batch
     */
    private function batchFormResponse(
        string $template,
        array $form,
        array $errors,
        array $photos,
        ?array $batch,
        int $status,
    ): Response {
        $unitSystem = $this->unitSystem();
        return Response::html($this->render($template, [
            'title' => $batch === null ? 'New Batch' : 'Edit Batch #' . (int) $batch['id'],
            'activeNav' => $batch === null ? 'new-batch' : 'batches',
            'form' => $form,
            'errors' => $errors,
            'presets' => $this->displayPresetGroups($unitSystem),
            'templates' => $this->templates->allForForms($unitSystem),
            'photos' => $photos,
            'batch' => $batch,
            'batchId' => $batch === null ? null : (int) $batch['id'],
            'unitSystem' => $unitSystem,
        ]), $status);
    }

    /**
     * @param array<string, mixed> $batch
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     * @param array<string, mixed>|null $pass
     */
    private function passFormResponse(
        string $template,
        array $batch,
        array $values,
        array $errors,
        ?array $pass,
        int $status,
    ): Response {
        $position = $pass === null
            ? count(is_array($batch['passes'] ?? null) ? $batch['passes'] : []) + 1
            : (int) $pass['position'];
        return Response::html($this->render($template, [
            'title' => ($pass === null ? 'Add' : 'Edit') . ' Pass ' . $position,
            'activeNav' => 'batches',
            'batch' => $batch,
            'batchId' => (int) $batch['id'],
            'pass' => $pass,
            'position' => $position,
            'values' => $values,
            'errors' => $errors,
            'presets' => $this->displayPresetGroups($this->unitSystem()),
            'unitSystem' => $this->unitSystem(),
        ]), $status);
    }

    /** @return array<string, mixed> */
    private function submittedPassForm(Request $request): array
    {
        $system = $this->unitSystem();
        return array_replace([
            'unit_system' => $system,
            'temperature_unit' => Units::temperatureUnitForSystem($system),
            'pressure_unit' => Units::pressureUnitForSystem($system),
            'temperature' => '',
            'pressure' => '',
            'preheat' => '',
            'press_duration' => '',
        ], $request->post);
    }

    /** @return array<string, mixed> */
    private function emptyBatchForm(string $unitSystem): array
    {
        $system = Units::normalizeSystem($unitSystem);
        $temperatureUnit = Units::temperatureUnitForSystem($system);
        return [
            'pressed_at' => '',
            'pressed_at_mode' => 'automatic',
            'unit_system' => $system,
            'weight_unit' => Units::weightUnitForSystem($system),
            'temperature_unit' => $temperatureUnit,
            'pressure_unit' => Units::pressureUnitForSystem($system),
            'bag_size_unit' => Units::lengthUnitForSystem($system),
            'source_template_id' => '',
            'strains' => [''],
            'strain_amounts' => [''],
            'start_material' => 'Flower',
            'start_amount' => '',
            'press_capacity' => '',
            'temperature' => Units::celsiusToTemperature(90.0, $temperatureUnit),
            'pressure' => '',
            'humidity' => '',
            'press_duration' => '',
            'preheat' => '',
            'bags' => [[
                'layer' => 1,
                'micron' => '',
                'width' => '',
                'length' => '',
                'unit' => Units::lengthUnitForSystem($system),
            ]],
            'yield_amount' => '',
            'notes' => '',
        ];
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $templateValues @return array<string, mixed> */
    private function applyTemplateToNewForm(array $base, array $templateValues, int $templateId): array
    {
        $form = array_replace($base, $templateValues);
        foreach (['pressed_at', 'pressed_at_mode', 'yield_amount', 'notes'] as $batchSpecific) {
            $form[$batchSpecific] = $base[$batchSpecific];
        }
        $form['source_template_id'] = $templateId;
        return $form;
    }

    private function templateWithSubmittedStrains(
        BatchTemplateData $template,
        Request $request,
        string $unitSystem,
        ?BatchTemplateData $fallback = null,
    ): BatchTemplateData {
        $hasSubmittedStrains = $request->input('template_strains_present') === '1'
            || array_key_exists('strains', $request->post)
            || array_key_exists('strain', $request->post)
            || array_key_exists('strain_amounts', $request->post)
            || array_key_exists('strainAmounts', $request->post);
        if (!$hasSubmittedStrains && $fallback !== null) {
            return $template->withStrains($fallback->strains, $fallback->strainAmountsG);
        }

        $submittedUnitSystem = $request->input('unit_system');
        if ($submittedUnitSystem === '') {
            $submittedUnitSystem = $unitSystem;
        }

        return $template->withFormStrains(
            $request->post['strains'] ?? $request->post['strain'] ?? [],
            $request->post['strain_amounts'] ?? $request->post['strainAmounts'] ?? [],
            $request->post['weight_unit'] ?? Units::weightUnitForSystem($submittedUnitSystem),
            $fallback,
        );
    }

    /** @return array<string, mixed> */
    private function submittedBatchForm(
        Request $request,
        string $unitSystem,
        bool $forceAutomaticPressTime = false,
    ): array
    {
        $form = array_replace($this->emptyBatchForm($unitSystem), $request->post);
        if ($forceAutomaticPressTime) {
            $form['pressed_at'] = '';
            $form['pressed_at_mode'] = 'automatic';
        }
        $form['unit_system'] = $request->input('unit_system', $unitSystem);
        return $form;
    }

    /** @return array<string, mixed> */
    private function photoUploadBag(Request $request): array
    {
        $bag = $request->files['photos'] ?? [];
        return is_array($bag) ? $bag : [];
    }

    /** @param array<string, mixed> $batch @return list<int> */
    private function selectedPhotoRemovals(Request $request, array $batch): array
    {
        $available = [];
        foreach ($batch['photos'] as $photo) {
            $available[(int) $photo['id']] = true;
        }
        $selected = [];
        foreach ($request->arrayInput('remove_photos') as $rawId) {
            if (!ctype_digit($rawId) || (int) $rawId < 1 || !isset($available[(int) $rawId])) {
                throw new ValidationException(['photos' => 'A selected photograph does not belong to this batch.']);
            }
            $selected[(int) $rawId] = true;
        }
        return array_keys($selected);
    }

    /** @param list<array<string, mixed>> $photos @return list<array<string, mixed>> */
    private function photoUrls(array $photos): array
    {
        return array_map(static function (array $photo): array {
            $photo['url'] = '/photos/' . (int) $photo['id'];
            return $photo;
        }, array_values($photos));
    }

    /** @param list<string> $storageNames */
    private function deleteStoredPhotos(array $storageNames): void
    {
        foreach ($storageNames as $storageName) {
            $this->photoStorage->delete($storageName);
        }
    }

    private function presetValue(
        string $field,
        string $raw,
        ?Request $request = null,
        mixed $existingValue = null,
    ): string|int|float|array|null
    {
        if (!isset(self::PRESET_FIELDS[$field])) {
            throw new ValidationException(['field_key' => 'Choose a supported preset field.']);
        }
        if ($field === 'bag') {
            if (!$request instanceof Request) {
                throw new ValidationException(['bag' => 'Enter the bag dimensions and micron rating.']);
            }
            $width = $this->presetNumber($request->input('width'), 'width');
            $length = $this->presetNumber($request->input('length'), 'length');
            $micron = $this->presetNumber($request->input('micron'), 'micron');
            try {
                $unit = Units::normalizeLengthUnit(
                    $request->input('bag_size_unit', Units::lengthUnitForSystem($this->unitSystem())),
                );
                $widthMm = Units::lengthToMillimetres($width, $unit);
                $lengthMm = Units::lengthToMillimetres($length, $unit);
            } catch (InvalidArgumentException) {
                throw new ValidationException(['bag_size_unit' => 'Choose millimetres or inches.']);
            }
            if ($widthMm <= 0 || $widthMm > 1000 || $lengthMm <= 0 || $lengthMm > 2000) {
                throw new ValidationException(['bag' => 'Enter bag dimensions within the supported range.']);
            }
            if ($micron < 1 || $micron > 500 || floor($micron) !== $micron) {
                throw new ValidationException(['micron' => 'Enter a whole-number micron rating from 1 to 500.']);
            }
            $brand = $this->presetBrand($request->input('brand'));
            return [
                'brand' => $brand,
                'micron' => (int) $micron,
                'widthMm' => $widthMm,
                'lengthMm' => $lengthMm,
            ];
        }
        if ($field === 'start_material') {
            if (!$request instanceof Request) {
                throw new ValidationException(['start_material' => 'Enter a material name.']);
            }
            return [
                'chart' => $this->submittedMaterialChartStyle(
                    $request,
                    $this->presetSubmittedName($request),
                    $existingValue,
                ),
            ];
        }
        if (self::PRESET_FIELDS[$field]['type'] === 'text') {
            return null;
        }
        $value = trim($raw);
        if ($value === '') {
            throw new ValidationException(['value' => 'Enter a preset value.']);
        }
        $normalized = str_replace(',', '.', $value);
        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $normalized) !== 1) {
            throw new ValidationException(['value' => 'Enter a valid number.']);
        }
        $number = (float) $normalized;
        if (!is_finite($number)) {
            throw new ValidationException(['value' => 'Enter a finite number.']);
        }
        $temperatureRange = $this->unitSystem() === Units::IMPERIAL
            ? [-58.0, 572.0]
            : [-50.0, 300.0];
        $ranges = [
            'press_capacity' => [0.000001, 1000.0, false],
            'temperature' => [$temperatureRange[0], $temperatureRange[1], false],
            'pressure' => [0.0, 100000.0, false],
            'humidity' => [0.0, 100.0, false],
            'press_duration' => [0.0, 7200.0, true],
            'preheat' => [0.0, 7200.0, true],
        ];
        [$minimum, $maximum, $integer] = $ranges[$field];
        if ($number < $minimum || $number > $maximum || ($integer && floor($number) !== $number)) {
            throw new ValidationException(['value' => 'The preset value is outside the supported range.']);
        }
        if ($field === 'temperature') {
            return [
                'value' => Units::temperatureToCelsius(
                    $number,
                    Units::temperatureUnitForSystem($this->unitSystem()),
                ),
                'unit' => 'c',
            ];
        }
        if ($field === 'pressure') {
            return [
                'value' => Units::pressureToBar(
                    $number,
                    Units::pressureUnitForSystem($this->unitSystem()),
                ),
                'unit' => 'bar',
            ];
        }
        return $integer ? (int) $number : $number;
    }

    private function presetNumber(string $raw, string $field): float
    {
        $value = str_replace(',', '.', trim($raw));
        if ($value === '' || preg_match('/^[+]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $value) !== 1) {
            throw new ValidationException([$field => 'Enter a valid positive number.']);
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            throw new ValidationException([$field => 'Enter a finite number.']);
        }
        return $number;
    }

    private function presetSubmittedName(Request $request): string
    {
        foreach (['name', 'label', 'value'] as $field) {
            $value = $request->input($field);
            if (trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    private function presetBrand(string $raw): ?string
    {
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $raw);
        $normalized = is_string($trimmed) ? preg_replace('/\s+/u', ' ', $trimmed) : null;
        if (!is_string($normalized)) {
            throw new ValidationException(['brand' => 'Enter a valid bag brand.']);
        }
        if (mb_strlen($normalized) > 80) {
            throw new ValidationException(['brand' => 'Use no more than 80 characters for the bag brand.']);
        }

        return $normalized === '' ? null : $normalized;
    }

    private function presetLabel(string $field, string $submitted, string|int|float|array|null $value): string
    {
        if ($field !== 'bag') {
            return $submitted;
        }
        if (!is_array($value)) {
            throw new ValidationException(['bag' => 'Enter the bag dimensions and micron rating.']);
        }
        $dimensions = $this->numberLabel((float) $value['widthMm'])
            . ' × ' . $this->numberLabel((float) $value['lengthMm'])
            . ' mm · ' . (int) $value['micron'] . ' μm';
        $brand = isset($value['brand']) && is_string($value['brand']) ? trim($value['brand']) : '';

        return $brand === '' ? $dimensions : $brand . ' · ' . $dimensions;
    }

    /** @return array{color:string,marker:string} */
    private function submittedMaterialChartStyle(Request $request, string $label, mixed $existingValue): array
    {
        $style = $this->normalizedMaterialChartStyle($existingValue, $label);
        if (array_key_exists('chart_color', $request->post)) {
            $color = strtolower(trim($request->input('chart_color')));
            if ($color === '') {
                $color = $this->defaultMaterialChartColor($label);
            }
            if (!in_array($color, self::MATERIAL_CHART_COLORS, true)) {
                throw new ValidationException(['chart_color' => 'Choose one of the available chart colors.']);
            }
            $style['color'] = $color;
        }
        if (array_key_exists('chart_marker', $request->post)) {
            $marker = strtolower(trim($request->input('chart_marker')));
            if ($marker === '') {
                $marker = 'burst';
            }
            if (!in_array($marker, self::MATERIAL_CHART_MARKERS, true)) {
                throw new ValidationException(['chart_marker' => 'Choose one of the available chart markers.']);
            }
            $style['marker'] = $marker;
        }

        return $style;
    }

    /** @return array{color:string,marker:string} */
    private function normalizedMaterialChartStyle(mixed $value, string $label): array
    {
        $chart = is_array($value) && is_array($value['chart'] ?? null) ? $value['chart'] : [];
        $color = isset($chart['color']) && is_string($chart['color'])
            ? strtolower(trim($chart['color']))
            : '';
        $marker = isset($chart['marker']) && is_string($chart['marker'])
            ? strtolower(trim($chart['marker']))
            : '';

        return [
            'color' => in_array($color, self::MATERIAL_CHART_COLORS, true)
                ? $color
                : $this->defaultMaterialChartColor($label),
            'marker' => in_array($marker, self::MATERIAL_CHART_MARKERS, true) ? $marker : 'burst',
        ];
    }

    private function defaultMaterialChartColor(string $label): string
    {
        $normalized = mb_strtolower(trim($label));
        $common = [
            'flower' => '#75beff',
            'hash' => '#73c991',
            'kief' => '#dcdcaa',
            'trim' => '#c586c0',
            'not recorded' => '#ce9178',
        ];
        if (isset($common[$normalized])) {
            return $common[$normalized];
        }
        $digest = hash('sha256', $normalized, true);
        $index = ord($digest[0]) % count(self::MATERIAL_CHART_COLORS);

        return self::MATERIAL_CHART_COLORS[$index];
    }

    /** @return array<string, array{color:string,marker:string}> */
    private function materialChartStyles(): array
    {
        $styles = [];
        foreach ($this->presets->forField('start_material') as $option) {
            $label = trim((string) ($option['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $styles[mb_strtolower($label)] = $this->normalizedMaterialChartStyle(
                $option['value'] ?? null,
                $label,
            );
        }

        return $styles;
    }

    private function numberLabel(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function displayPresetGroups(string $unitSystem): array
    {
        $groups = array_intersect_key($this->presets->grouped(), self::PRESET_FIELDS);
        foreach ($groups as $field => &$options) {
            foreach ($options as &$option) {
                $stored = $option['value'] ?? null;
                if ($field === 'start_material') {
                    $option['value'] = [
                        'chart' => $this->normalizedMaterialChartStyle(
                            $stored,
                            (string) ($option['label'] ?? ''),
                        ),
                    ];
                    continue;
                }
                if ($field === 'strain') {
                    // A strain preset has one canonical Name: its visible label.
                    // Ignore retired duplicate values from old imports or tools.
                    $option['value'] = null;
                    continue;
                }
                if ($field === 'bag'
                    && is_array($stored)
                    && isset($stored['micron'], $stored['widthMm'], $stored['lengthMm'])
                    && is_numeric($stored['micron'])
                    && is_numeric($stored['widthMm'])
                    && is_numeric($stored['lengthMm'])) {
                    $unit = Units::lengthUnitForSystem($unitSystem);
                    $width = Units::millimetresToLength((float) $stored['widthMm'], $unit);
                    $length = Units::millimetresToLength((float) $stored['lengthMm'], $unit);
                    $option['value'] = [
                        'brand' => isset($stored['brand']) && is_string($stored['brand'])
                            ? trim($stored['brand'])
                            : null,
                        'micron' => (int) $stored['micron'],
                        'width' => $width,
                        'length' => $length,
                        'unit' => $unit,
                        'widthMm' => (float) $stored['widthMm'],
                        'lengthMm' => (float) $stored['lengthMm'],
                    ];
                    $dimensions = $this->numberLabel($width)
                        . ' × ' . $this->numberLabel($length)
                        . ' ' . $unit . ' · ' . (int) $stored['micron'] . ' μm';
                    $brand = isset($stored['brand']) && is_string($stored['brand'])
                        ? trim($stored['brand'])
                        : '';
                    $option['label'] = $brand === '' ? $dimensions : $brand . ' · ' . $dimensions;
                    continue;
                }
                if (!is_array($stored) || !isset($stored['value']) || !is_numeric($stored['value'])) {
                    continue;
                }
                if ($field === 'temperature' && ($stored['unit'] ?? null) === 'c') {
                    $option['value'] = Units::celsiusToTemperature(
                        (float) $stored['value'],
                        Units::temperatureUnitForSystem($unitSystem),
                    );
                } elseif ($field === 'pressure' && ($stored['unit'] ?? null) === 'bar') {
                    $option['value'] = Units::barToPressure(
                        (float) $stored['value'],
                        Units::pressureUnitForSystem($unitSystem),
                    );
                }
            }
            unset($option);
        }
        unset($options);
        return $groups;
    }

    private function sortOrder(string $raw): int
    {
        if ($raw === '') {
            return 0;
        }
        if (preg_match('/^-?\d+$/D', $raw) !== 1) {
            throw new ValidationException(['sort_order' => 'Enter a whole-number sort order.']);
        }
        return (int) $raw;
    }

    private function unitSystem(): string
    {
        try {
            return Units::normalizeSystem($this->settings->get('unit_system', Units::METRIC));
        } catch (InvalidArgumentException) {
            return Units::METRIC;
        }
    }

    private function resolveExternalUrl(): string
    {
        if ($this->config->externalUrlManaged) {
            return ExternalUrl::fromString($this->config->externalUrl)->value();
        }

        $stored = $this->settings->get('external_url');
        if ($stored !== '') {
            return ExternalUrl::fromString($stored)->value();
        }

        // Preserve the original LAN-only fallback until the owner saves a canonical HTTPS URL.
        return rtrim($this->config->externalUrl, '/');
    }

    private function timezone(): string
    {
        $timezone = $this->settings->get('timezone', $this->config->timezone);
        try {
            return (new DateTimeZone($timezone))->getName();
        } catch (Throwable) {
            return $this->config->timezone;
        }
    }

    private function accent(): string
    {
        $accent = strtolower($this->settings->get('accent', 'blue'));
        return in_array($accent, ['blue', 'green', 'red'], true) ? $accent : 'blue';
    }

    private function query(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query[$key] ?? $default;
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** @param list<string> $options */
    private function analyticsOption(string $raw, array $options): string
    {
        if ($raw === '' || strcasecmp($raw, 'all') === 0) {
            return '';
        }
        $normalized = mb_strtolower(trim($raw));
        foreach ($options as $option) {
            if (mb_strtolower(trim($option)) === $normalized) {
                return $option;
            }
        }
        return '';
    }

    /** @param list<string> $options */
    private function effectiveAnalyticsMaterial(Request $request, array $options): string
    {
        if (array_key_exists('material', $request->query)) {
            return $this->analyticsOption($this->query($request, 'material'), $options);
        }

        return $this->analyticsOption(
            $this->settings->get('analytics_default_material'),
            $options,
        );
    }

    private function positiveQueryId(Request $request, string $key): ?int
    {
        $value = $this->query($request, $key);
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @param array<string, string> $errors */
    private function firstError(array $errors): string
    {
        $first = reset($errors);
        return is_string($first) && $first !== '' ? $first : 'Please check the submitted values.';
    }

    /** @return array<string, string> */
    private function validateOwnerSetup(string $username, string $password, string $confirmation): array
    {
        $errors = [];
        $length = mb_strlen($username);
        if ($length < 2 || $length > 64) {
            $errors['username'] = 'Use between 2 and 64 characters.';
        } elseif (preg_match('/^[\\p{L}\\p{N} ._-]+$/u', $username) !== 1) {
            $errors['username'] = 'Use letters, numbers, spaces, dots, hyphens, or underscores.';
        }
        $errors = array_replace(
            $errors,
            PasswordPolicy::validateWithConfirmation($password, $confirmation),
        );
        return $errors;
    }

    /** @param array<string, mixed> $data */
    private function render(string $template, array $data, ?string $layout = 'app'): string
    {
        return $this->view->render($template, array_merge([
            'owner' => $this->auth->owner(),
            'flashes' => $this->session->consumeFlashes(),
            'csrfToken' => $this->csrf->token(),
            'timezone' => $this->timezone(),
            'accent' => $this->accent(),
            'activeNav' => '',
            'title' => 'Rosin Tracker',
        ], $data), $layout);
    }

    private function notFound(string $title = 'Page not found'): Response
    {
        return Response::html($this->render('errors/404', ['title' => $title, 'activeNav' => '']), 404);
    }

    private function csrfFailureRedirect(Request $request): string
    {
        if ($request->path === '/setup') {
            return '/setup';
        }
        if ($request->path === '/login/totp' && $this->pendingLocalLogin() !== null) {
            return '/login/totp';
        }
        if ($request->path === '/login/oidc') {
            return '/login';
        }
        if (!$this->auth->check()) {
            return '/login';
        }
        if (preg_match('#^/batch/([1-9][0-9]*)(?:/edit)?$#', $request->path, $match) === 1) {
            return '/batch/' . $match[1] . (str_ends_with($request->path, '/edit') ? '/edit' : '');
        }
        if (preg_match('#^/batch/([1-9][0-9]*)/#', $request->path, $match) === 1) {
            return '/batch/' . $match[1];
        }
        if (str_starts_with($request->path, '/presets') || str_starts_with($request->path, '/templates')) {
            return '/presets';
        }
        if (str_starts_with($request->path, '/settings') || str_starts_with($request->path, '/backups')) {
            return '/settings';
        }
        return in_array($request->path, ['/new-press', '/batches/new'], true) ? '/batches/new' : '/';
    }

    private function recordLoginFailure(): void
    {
        $this->authentication->recordLoginFailure();
    }

    private function clearLoginFailures(): void
    {
        $this->authentication->clearLoginFailures();
    }

    private function loginBlockedFor(): int
    {
        return $this->authentication->loginBlockedFor();
    }
}
