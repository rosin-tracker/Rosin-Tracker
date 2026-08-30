<?php

declare(strict_types=1);

use RosinTracker\Application;
use RosinTracker\Domain\BatchData;
use RosinTracker\Domain\BatchTemplateData;
use RosinTracker\Domain\ValidationException;
use RosinTracker\Http\Request;
use RosinTracker\Http\Response;
use RosinTracker\Repository\AuthenticationRepository;

$temporaryRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'rosin-tracker-smoke-' . bin2hex(random_bytes(6));
putenv('ROSIN_TRACKER_DATA_DIR=' . $temporaryRoot);

/** @param array<string, mixed> $post */
function request(Application $application, string $method, string $path, array $post = [], array $query = []): Response
{
    return $application->run(new Request($method, $path, $query, $post, [], []));
}

function requireStatus(Response $response, int $expected, string $step): void
{
    if ($response->status !== $expected) {
        throw new RuntimeException("{$step} returned HTTP {$response->status}; expected {$expected}.");
    }
}

function csrfFrom(Response $response): string
{
    if (preg_match('/name="_csrf" value="([^"]+)"/', $response->body, $match) !== 1) {
        throw new RuntimeException('A rendered form did not contain a CSRF token.');
    }
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function removeSmokeDirectory(string $directory): void
{
    $resolved = realpath($directory);
    $temporary = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporary === false
        || !str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR . 'rosin-tracker-smoke-')) {
        throw new RuntimeException('Refusing unsafe smoke-test cleanup: ' . $directory);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($resolved);
}

$exitCode = 0;
try {
    $config = require dirname(__DIR__) . '/src/bootstrap.php';
    $application = new Application($config);

    $setup = request($application, 'GET', '/setup');
    requireStatus($setup, 200, 'Owner setup page');
    $setupToken = csrfFrom($setup);
    $createdOwner = request($application, 'POST', '/setup', [
        '_csrf' => $setupToken,
        'username' => 'Smoke Owner',
        'password' => 'smoke888',
        'password_confirmation' => 'smoke888',
    ]);
    requireStatus($createdOwner, 303, 'Owner creation');

    $sessionEpoch = $_SESSION['_owner_session_epoch'] ?? null;
    if (!is_int($sessionEpoch)) {
        throw new RuntimeException('Owner setup did not bind the authenticated session epoch.');
    }
    $handoffTarget = 'https://identity.example.test/authorize?client_id=rosin&amp;state=smoke-state';
    $_SESSION['oidc_flow'] = [
        'url' => html_entity_decode($handoffTarget, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'purpose' => 'link',
        'issuedAt' => time(),
        'sessionEpoch' => $sessionEpoch,
    ];
    $oidcHandoff = request($application, 'GET', '/auth/oidc/continue');
    requireStatus($oidcHandoff, 200, 'OpenID browser handoff');
    if (!str_contains($oidcHandoff->body, 'href="' . $handoffTarget . '"')
        || !str_contains($oidcHandoff->body, 'data-oidc-continue')) {
        throw new RuntimeException('OpenID did not render a CSP-safe browser handoff.');
    }
    $oidcCanceled = request($application, 'POST', '/auth/oidc/cancel', [
        '_csrf' => csrfFrom($oidcHandoff),
    ]);
    requireStatus($oidcCanceled, 303, 'OpenID handoff cancellation');
    if (($oidcCanceled->headers['Location'] ?? '') !== '/settings' || isset($_SESSION['oidc_flow'])) {
        throw new RuntimeException('Canceling the OpenID handoff did not clear its session state.');
    }

    $newForm = request($application, 'GET', '/batches/new');
    requireStatus($newForm, 200, 'New batch page');
    if (!str_contains($newForm->body, 'name="pressed_at_mode" value="automatic"')
        || str_contains($newForm->body, 'name="pressed_at"')
        || str_contains($newForm->body, 'data-custom-time')) {
        throw new RuntimeException('New Batch still exposed a configurable press time.');
    }
    if (!str_contains($newForm->body, 'class="pass-form-row" data-pass-row')) {
        throw new RuntimeException('New Batch did not render its compact pass row.');
    }
    $token = csrfFrom($newForm);
    $materialPreset = request($application, 'POST', '/presets', [
        '_csrf' => $token,
        'field_key' => 'start_material',
        'name' => 'Fresh Frozen',
        'chart_color' => '#C586C0',
        'chart_marker' => 'triangle',
        'sort_order' => '50',
    ]);
    requireStatus($materialPreset, 303, 'Named material preset creation');
    $unsafeColorPreset = request($application, 'POST', '/presets', [
        '_csrf' => $token,
        'field_key' => 'start_material',
        'name' => 'Unsafe Color',
        'chart_color' => '#75beff; background: url(javascript:alert(1))',
        'chart_marker' => 'burst',
        'sort_order' => '60',
    ]);
    requireStatus($unsafeColorPreset, 303, 'Unsafe material color rejection');
    $unsafeMarkerPreset = request($application, 'POST', '/presets', [
        '_csrf' => $token,
        'field_key' => 'start_material',
        'name' => 'Unsafe Marker',
        'chart_color' => '#75beff',
        'chart_marker' => '<script>alert(1)</script>',
        'sort_order' => '70',
    ]);
    requireStatus($unsafeMarkerPreset, 303, 'Unsafe material marker rejection');
    $strainPreset = request($application, 'POST', '/presets', [
        '_csrf' => $token,
        'field_key' => 'strain',
        'name' => 'Smoke Select',
        'sort_order' => '50',
    ]);
    requireStatus($strainPreset, 303, 'Named strain preset creation');
    $namedPresetForm = request($application, 'GET', '/batches/new');
    if (substr_count($namedPresetForm->body, 'data-saved-combobox') < 2
        || substr_count($namedPresetForm->body, 'data-combobox-list') < 2
        || !str_contains($namedPresetForm->body, 'data-value="Fresh Frozen">Fresh Frozen</button>')
        || !str_contains($namedPresetForm->body, 'data-value="Smoke Select">Smoke Select</button>')) {
        throw new RuntimeException('Named presets were not exposed through the New Batch pickers.');
    }
    $batchForm = [
        '_csrf' => $token,
        'pressed_at_mode' => 'custom',
        'pressed_at' => '2000-01-01T00:00',
        'unit_system' => 'metric',
        'weight_unit' => 'g',
        'temperature_unit' => 'c',
        'pressure_unit' => 'bar',
        'bag_size_unit' => 'mm',
        'source_template_id' => '',
        'strains' => ['Smoke Strain', 'Smoke Select'],
        'strain_amounts' => ['6', '4'],
        'start_material' => 'Flower',
        'start_amount' => '10',
        'yield_amount' => '2',
        'press_capacity' => '10',
        'humidity' => '62',
        'notes' => 'Focused smoke test.',
        'passes' => [
            [
                'temperature' => '90',
                'pressure' => '70',
                'press_duration' => '120',
                'preheat' => '45',
            ],
            [
                'temperature' => '92',
                'pressure' => '75',
                'press_duration' => '90',
                'preheat' => '30',
            ],
        ],
        'bags' => [[
            'brand' => 'The Press Club',
            'micron' => '90',
            'width' => '50',
            'length' => '100',
            'unit' => 'mm',
            'layer' => '1',
        ]],
        'save_as_template' => '1',
        'template_name' => 'Smoke Template',
    ];
    $automaticBefore = time();
    $createdBatch = request($application, 'POST', '/batches/new', $batchForm);
    $automaticAfter = time();
    requireStatus($createdBatch, 303, 'Batch creation');
    if (($createdBatch->headers['Location'] ?? '') !== '/batch/1') {
        throw new RuntimeException('Batch creation did not redirect to the new record.');
    }
    $templateEditForm = $batchForm;
    $templateEditForm['name'] = 'Smoke Template';
    $updatedTemplate = request($application, 'POST', '/templates/1/edit', $templateEditForm);
    requireStatus($updatedTemplate, 303, 'Template strain update');
    if (($updatedTemplate->headers['Location'] ?? '') !== '/presets') {
        throw new RuntimeException('Template editing did not return to Presets.');
    }
    $legacyTemplateEditForm = $templateEditForm;
    unset($legacyTemplateEditForm['strains'], $legacyTemplateEditForm['strain_amounts']);
    $legacyTemplateUpdate = request($application, 'POST', '/templates/1/edit', $legacyTemplateEditForm);
    requireStatus($legacyTemplateUpdate, 303, 'Legacy template update');

    $analyticsDefaultUpdate = request($application, 'POST', '/settings/analytics-material', [
        '_csrf' => $token,
        'analytics_default_material' => 'flower',
    ]);
    requireStatus($analyticsDefaultUpdate, 303, 'Default analytics material update');
    $invalidAnalyticsDefaultUpdate = request($application, 'POST', '/settings/analytics-material', [
        '_csrf' => $token,
        'analytics_default_material' => 'Not a saved material',
    ]);
    requireStatus($invalidAnalyticsDefaultUpdate, 303, 'Invalid default analytics material rejection');
    $settingsWithAnalyticsDefault = request($application, 'GET', '/settings');
    if (!str_contains(
        $settingsWithAnalyticsDefault->body,
        '<option value="Flower" selected>Flower</option>',
    )) {
        throw new RuntimeException('Settings did not show the canonical default analytics material.');
    }
    $defaultDashboard = request($application, 'GET', '/');
    $defaultAnalytics = request($application, 'GET', '/analytics');
    if (!str_contains($defaultDashboard->body, '<option value="Flower" selected>Flower</option>')
        || !str_contains($defaultAnalytics->body, '<option value="Flower" selected>Flower</option>')) {
        throw new RuntimeException('The saved material was not applied when an analytics query was absent.');
    }
    if (!str_contains($defaultDashboard->body, 'Highest Yield')
        || !str_contains($defaultDashboard->body, 'href="/batch/1"')) {
        throw new RuntimeException('Dashboard did not render the highest matching batch card.');
    }
    $dashboardCardPositions = array_map(
        static fn (string $label): int|false => strpos($defaultDashboard->body, '<span class="stat-label">' . $label . '</span>'),
        ['Typical Yield', 'Overall Yield', 'Highest Yield', 'Batches'],
    );
    if (in_array(false, $dashboardCardPositions, true)
        || !($dashboardCardPositions[0] < $dashboardCardPositions[1]
            && $dashboardCardPositions[1] < $dashboardCardPositions[2]
            && $dashboardCardPositions[2] < $dashboardCardPositions[3])
        || str_contains($defaultDashboard->body, 'Yield Consistency')) {
        throw new RuntimeException('Dashboard summary cards are missing or out of order.');
    }
    if (!str_contains($defaultDashboard->body, '#marker-burst')
        || !str_contains($defaultAnalytics->body, '#marker-burst')) {
        throw new RuntimeException('Dashboard or Analytics did not render the default Burst material marker.');
    }
    $allMaterialsDashboard = request($application, 'GET', '/', [], ['material' => '']);
    $invalidMaterialAnalytics = request($application, 'GET', '/analytics', [], ['material' => 'Not a material']);
    if (str_contains($allMaterialsDashboard->body, '<option value="Flower" selected>Flower</option>')
        || str_contains($invalidMaterialAnalytics->body, '<option value="Flower" selected>Flower</option>')) {
        throw new RuntimeException('An explicit All or invalid material filter did not override the saved default.');
    }

    foreach (['/' => 'Dashboard', '/batches' => 'All Batches', '/batch/1' => 'Batch detail',
        '/batch/1/edit' => 'Batch edit', '/presets' => 'Presets', '/analytics' => 'Analytics',
        '/settings' => 'Settings'] as $path => $label) {
        $response = request($application, 'GET', $path);
        requireStatus($response, 200, $label);
        if (trim($response->body) === '') {
            throw new RuntimeException($label . ' rendered an empty response.');
        }
        if ($path === '/settings'
            && str_contains($response->body, 'OpenID dependencies are not installed.')) {
            throw new RuntimeException('The OpenID Composer dependencies are not loadable.');
        }
    }

    $newPass = request($application, 'GET', '/batch/1/passes/new');
    requireStatus($newPass, 200, 'Add pass page');
    if (!str_contains($newPass->body, 'value="92"')) {
        throw new RuntimeException('Add Pass did not copy the previous pass settings.');
    }
    $detailWithPass = request($application, 'GET', '/batch/1');
    if (!str_contains($detailWithPass->body, 'Pass 2')) {
        throw new RuntimeException('New Batch did not render its second pass.');
    }

    $retiredPreset = request($application, 'POST', '/presets', [
        '_csrf' => $token,
        'field_key' => 'temperature',
        'label' => 'Retired temperature',
        'value' => '90',
        'sort_order' => '10',
    ]);
    requireStatus($retiredPreset, 303, 'Retired preset rejection');
    $retiredHumidityPreset = request($application, 'POST', '/presets', [
        '_csrf' => $token,
        'field_key' => 'humidity',
        'label' => 'Dry room',
        'value' => '62',
        'sort_order' => '10',
    ]);
    requireStatus($retiredHumidityPreset, 303, 'Retired humidity preset rejection');
    $bagPreset = request($application, 'POST', '/presets', [
        '_csrf' => $token,
        'field_key' => 'bag',
        'label' => '',
        'value' => '',
        'bag_size_unit' => 'mm',
        'brand' => 'The Press Club',
        'width' => '50',
        'length' => '100',
        'micron' => '90',
        'sort_order' => '10',
    ]);
    requireStatus($bagPreset, 303, 'Combined bag preset creation');
    $units = request($application, 'POST', '/settings/units', [
        '_csrf' => $token,
        'unit_system' => 'imperial',
    ]);
    requireStatus($units, 303, 'Unit switch');
    $imperialForm = request($application, 'GET', '/batches/new', [], ['template' => '1']);
    requireStatus($imperialForm, 200, 'Imperial templated batch page');
    if (preg_match('/name="passes\[0\]\[temperature\]"[^>]*value="194"/', $imperialForm->body) !== 1
        || preg_match('/name="passes\[1\]\[temperature\]"[^>]*value="197\.6"/', $imperialForm->body) !== 1) {
        throw new RuntimeException('The complete template pass sequence was not converted for display.');
    }
    if (!str_contains($imperialForm->body, 'name="strains[]" type="text" value="Smoke Strain"')
        || !str_contains($imperialForm->body, 'name="strains[]" type="text" value="Smoke Select"')
        || !str_contains($imperialForm->body, 'name="strain_amounts[]" type="number" min="0.0001" step="0.0001" inputmode="decimal" value="0.2116"')
        || !str_contains($imperialForm->body, 'name="strain_amounts[]" type="number" min="0.0001" step="0.0001" inputmode="decimal" value="0.1411"')
        || !str_contains($imperialForm->body, 'name="start_amount" type="number" min="0" step="0.0001" inputmode="decimal" value="0.3527"')) {
        throw new RuntimeException('The template strains and amounts were not converted for a new batch.');
    }
    if (!str_contains($imperialForm->body, '1.97 × 3.94 in · 90 μm')) {
        throw new RuntimeException('Combined bag presets were not converted for display.');
    }
    $imperialTemplateUpdate = request($application, 'POST', '/templates/1/edit', [
        '_csrf' => csrfFrom($imperialForm),
        'name' => 'Smoke Template',
        'template_strains_present' => '1',
        'unit_system' => 'imperial',
        'weight_unit' => 'oz',
        'temperature_unit' => 'f',
        'pressure_unit' => 'psi',
        'bag_size_unit' => 'in',
        'start_material' => 'Flower',
        'press_capacity' => '10',
        'humidity' => '62',
        'strains' => ['Smoke Strain', 'Smoke Select'],
        'strain_amounts' => ['0.2116', '0.1411'],
        'passes' => [
            ['temperature' => '194', 'pressure' => '1015.2642', 'press_duration' => '120', 'preheat' => '45'],
            ['temperature' => '197.6', 'pressure' => '1087.7828', 'press_duration' => '90', 'preheat' => '30'],
        ],
        'bags' => [[
            'brand' => 'The Press Club',
            'micron' => '90',
            'width' => '1.9685',
            'length' => '3.937',
            'unit' => 'in',
            'layer' => '1',
        ]],
    ]);
    requireStatus($imperialTemplateUpdate, 303, 'Imperial template update');

    $accent = request($application, 'POST', '/settings/accent', [
        '_csrf' => $token,
        'accent' => 'green',
    ]);
    requireStatus($accent, 303, 'Accent switch');
    $greenSettings = request($application, 'GET', '/settings');
    if (!str_contains($greenSettings->body, 'data-accent="green"')) {
        throw new RuntimeException('The selected accent was not applied to the page layout.');
    }

    $totpStart = request($application, 'POST', '/settings/authentication/totp/start', [
        '_csrf' => $token,
        'current_password' => 'smoke888',
    ]);
    requireStatus($totpStart, 303, 'Authenticator setup start');
    if (($totpStart->headers['Location'] ?? '') !== '/settings/authentication/totp/setup') {
        throw new RuntimeException('Authenticator setup could not start with the installed dependencies.');
    }
    $totpSetup = request($application, 'GET', '/settings/authentication/totp/setup');
    requireStatus($totpSetup, 200, 'Authenticator setup page');
    if (!str_contains($totpSetup->body, 'data:image/png;base64,')) {
        throw new RuntimeException('Authenticator setup did not render a local QR code.');
    }

    $backup = request($application, 'POST', '/settings/backups', ['_csrf' => $token]);
    requireStatus($backup, 303, 'Backup creation');
    $archives = glob($temporaryRoot . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . '*.zip');
    if (!is_array($archives) || count($archives) !== 1 || filesize($archives[0]) === 0) {
        throw new RuntimeException('The smoke-test backup was not created correctly.');
    }

    $database = new PDO(
        'sqlite:' . $temporaryRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'rosin-tracker.sqlite',
    );
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $materialPresetId = (int) $database->query(
        "SELECT id FROM preset_options WHERE field_key = 'start_material' AND label = 'Fresh Frozen'"
    )->fetchColumn();
    if ($materialPresetId < 1) {
        throw new RuntimeException('The styled material preset could not be found for its rename check.');
    }
    $renamedMaterialPreset = request($application, 'POST', '/presets/' . $materialPresetId . '/edit', [
        '_csrf' => $token,
        'field_key' => 'start_material',
        'name' => 'Fresh Frozen Renamed',
        'sort_order' => '50',
    ]);
    requireStatus($renamedMaterialPreset, 303, 'Styled material preset rename');
    $renamedMaterialForm = request($application, 'GET', '/batches/new');
    if (!str_contains(
        $renamedMaterialForm->body,
        'data-value="Fresh Frozen Renamed">Fresh Frozen Renamed</button>',
    )) {
        throw new RuntimeException('Renaming a styled material did not preserve its New Batch option.');
    }
    $strainPresetId = (int) $database->query(
        "SELECT id FROM preset_options WHERE field_key = 'strain' AND label = 'Smoke Select'"
    )->fetchColumn();
    $reclassifiedPreset = request($application, 'POST', '/presets/' . $strainPresetId . '/edit', [
        '_csrf' => $token,
        'field_key' => 'start_material',
        'name' => 'Reclassified',
        'sort_order' => '50',
    ]);
    requireStatus($reclassifiedPreset, 303, 'Preset type-change rejection');
    if ($database->query('SELECT field_key FROM preset_options WHERE id = ' . $strainPresetId)->fetchColumn() !== 'strain') {
        throw new RuntimeException('A forged edit changed an existing preset type.');
    }
    if ((int) $database->query('SELECT COUNT(*) FROM batches')->fetchColumn() !== 1
        || (int) $database->query('SELECT COUNT(*) FROM batch_templates')->fetchColumn() !== 1
        || (int) $database->query('SELECT COUNT(*) FROM batch_strains')->fetchColumn() !== 2
        || (int) $database->query('SELECT COUNT(*) FROM batch_bags')->fetchColumn() !== 1
        || (int) $database->query('SELECT COUNT(*) FROM batch_passes')->fetchColumn() !== 2) {
        throw new RuntimeException('The smoke-test database records are incomplete.');
    }
    $storedPressedAt = $database->query('SELECT pressed_at FROM batches WHERE id = 1')->fetchColumn();
    $storedTimestamp = is_string($storedPressedAt) ? strtotime($storedPressedAt) : false;
    if (!is_int($storedTimestamp) || $storedTimestamp < $automaticBefore || $storedTimestamp > $automaticAfter) {
        throw new RuntimeException('Automatic press time did not use the exact save time.');
    }
    $editTimestamp = (new DateTimeImmutable((string) $storedPressedAt))
        ->setTimezone(new DateTimeZone('Europe/Copenhagen'))
        ->format('Y-m-d\TH:i:s');
    $editForm = request($application, 'GET', '/batch/1/edit');
    if (!str_contains($editForm->body, 'value="' . $editTimestamp . '"')) {
        throw new RuntimeException('Batch editing did not preserve press-time seconds.');
    }
    if (preg_match('/name="bags\[0\]\[brand\]" value="The Press Club"/', $editForm->body) !== 1) {
        throw new RuntimeException('Batch editing did not preserve the optional bag brand.');
    }
    if (preg_match('/<input(?=[^>]*name="strain_amounts\[\]")(?=[^>]*value="0\.2116")[^>]*>/', $editForm->body) !== 1
        || preg_match('/<input(?=[^>]*name="strain_amounts\[\]")(?=[^>]*value="0\.1411")[^>]*>/', $editForm->body) !== 1) {
        throw new RuntimeException('Batch editing did not convert and refill the per-strain amounts.');
    }
    $invalidEditForm = $batchForm;
    $invalidEditForm['_csrf'] = csrfFrom($editForm);
    $invalidEditForm['pressed_at_mode'] = 'custom';
    $invalidEditForm['pressed_at'] = '2024-03-04T05:06:07';
    $invalidEditForm['start_material'] = '';
    $invalidEditForm['strain_amounts'] = ['6', '3'];
    $invalidEdit = request($application, 'POST', '/batch/1/edit', $invalidEditForm);
    requireStatus($invalidEdit, 422, 'Invalid batch edit');
    if (!str_contains($invalidEdit->body, 'value="2024-03-04T05:06:07"')
        || !str_contains($invalidEdit->body, 'Strain amounts must add up to the starting amount.')
        || preg_match('/<input(?=[^>]*name="strain_amounts\[\]")(?=[^>]*value="3")[^>]*>/', $invalidEdit->body) !== 1) {
        throw new RuntimeException('Batch edit validation did not retain the submitted time and strain amounts.');
    }
    if ((int) $database->query(
        "SELECT COUNT(*) FROM preset_options WHERE field_key = 'temperature'"
    )->fetchColumn() !== 0
        || (int) $database->query(
            "SELECT COUNT(*) FROM preset_options WHERE field_key = 'humidity' AND label = 'Dry room'"
        )->fetchColumn() !== 0) {
        throw new RuntimeException('The standalone preset allowlist was not enforced.');
    }
    $materialJson = $database->query(
        "SELECT value_json FROM preset_options "
        . "WHERE field_key = 'start_material' AND label = 'Fresh Frozen Renamed'"
    )->fetchColumn();
    $strainJson = $database->query(
        "SELECT value_json FROM preset_options "
        . "WHERE field_key = 'strain' AND label = 'Smoke Select'"
    )->fetchColumn();
    $materialValue = is_string($materialJson) ? json_decode($materialJson, true) : null;
    if (($materialValue['chart']['color'] ?? null) !== '#c586c0'
        || ($materialValue['chart']['marker'] ?? null) !== 'triangle'
        || $strainJson !== 'null') {
        throw new RuntimeException('Material chart metadata or scalar strain preset storage was not canonical.');
    }
    if ((int) $database->query(
        "SELECT COUNT(*) FROM preset_options WHERE label IN ('Unsafe Color', 'Unsafe Marker')"
    )->fetchColumn() !== 0
        || str_contains((string) $database->query(
            "SELECT GROUP_CONCAT(value_json, '') FROM preset_options"
        )->fetchColumn(), 'javascript:')
        || str_contains((string) $database->query(
            "SELECT GROUP_CONCAT(value_json, '') FROM preset_options"
        )->fetchColumn(), '<script>')) {
        throw new RuntimeException('Invalid material chart styles reached preset storage.');
    }
    $storedStrains = $database->query(
        'SELECT name, amount_g FROM batch_strains WHERE batch_id = 1 ORDER BY position, id'
    )->fetchAll(PDO::FETCH_ASSOC);
    if (count($storedStrains) !== 2
        || ($storedStrains[0]['name'] ?? null) !== 'Smoke Strain'
        || abs((float) ($storedStrains[0]['amount_g'] ?? -1) - 6.0) > 0.000001
        || ($storedStrains[1]['name'] ?? null) !== 'Smoke Select'
        || abs((float) ($storedStrains[1]['amount_g'] ?? -1) - 4.0) > 0.000001) {
        throw new RuntimeException('Per-strain amounts were not stored in canonical grams.');
    }
    $storedBagBrand = $database->query(
        'SELECT brand FROM batch_bags WHERE batch_id = 1 AND position = 0'
    )->fetchColumn();
    if ($storedBagBrand !== 'The Press Club') {
        throw new RuntimeException('The optional bag brand was not stored with the historical batch.');
    }
    $bagJson = $database->query(
        "SELECT value_json FROM preset_options "
        . "WHERE field_key = 'bag' AND label = 'The Press Club · 50 × 100 mm · 90 μm'"
    )->fetchColumn();
    $bagValue = is_string($bagJson) ? json_decode($bagJson, true) : null;
    if (!is_array($bagValue) || (int) ($bagValue['micron'] ?? 0) !== 90
        || (float) ($bagValue['widthMm'] ?? 0) !== 50.0
        || (float) ($bagValue['lengthMm'] ?? 0) !== 100.0
        || ($bagValue['brand'] ?? null) !== 'The Press Club') {
        throw new RuntimeException('Bag preset was not stored as one canonical bag option.');
    }
    if ((int) $database->query(
        "SELECT COUNT(*) FROM preset_options WHERE field_key = 'bag'"
    )->fetchColumn() !== 7
        || (int) $database->query(
            "SELECT COUNT(*) FROM preset_options WHERE field_key = 'legacy_bag_micron'"
        )->fetchColumn() !== 0) {
        throw new RuntimeException('The baseline did not retain only current canonical bag presets.');
    }
    $templateJson = $database->query(
        "SELECT payload_json FROM batch_templates WHERE name = 'Smoke Template'"
    )->fetchColumn();
    $templatePayload = is_string($templateJson) ? json_decode($templateJson, true) : null;
    if (!is_array($templatePayload) || ($templatePayload['schemaVersion'] ?? null) !== 3
        || !is_array($templatePayload['passes'] ?? null)
        || count($templatePayload['passes']) !== 2
        || ($templatePayload['strains'] ?? null) !== ['Smoke Strain', 'Smoke Select']
        || !is_array($templatePayload['strainAmountsG'] ?? null)
        || count($templatePayload['strainAmountsG']) !== 2
        || abs((float) $templatePayload['strainAmountsG'][0] - 6.0) > 0.000001
        || abs((float) $templatePayload['strainAmountsG'][1] - 4.0) > 0.000001
        || array_key_exists('strain_amounts', $templatePayload)
        || (float) ($templatePayload['passes'][0]['temperatureC'] ?? -999) !== 90.0
        || (float) ($templatePayload['passes'][1]['temperatureC'] ?? -999) !== 92.0
        || ($templatePayload['bags'][0]['brand'] ?? null) !== 'The Press Club') {
        throw new RuntimeException('The template did not preserve the complete ordered pass sequence.');
    }
    $singleStrainTemplate = BatchTemplateData::fromBatch(new BatchData(
        pressedAt: '2024-01-01T00:00:00+00:00',
        startMaterial: 'Flower',
        startAmountG: 10.0,
        yieldAmountG: 2.0,
        temperatureC: 90.0,
        pressureBar: null,
        pressCapacityTons: null,
        humidityPercent: null,
        pressDurationSeconds: null,
        preheatSeconds: null,
        numberOfPresses: 1,
        notes: null,
        sourceTemplateId: null,
        strains: ['Solo Strain'],
        bags: [],
        strainAmountsG: [null],
    ));
    $singleStrainPayload = $singleStrainTemplate->toPayload();
    $singleStrainRoundTrip = BatchTemplateData::fromPayload($singleStrainPayload);
    $singleStrainImperial = $singleStrainRoundTrip->toFormValues('imperial');
    $singleStrainEdited = $singleStrainRoundTrip->withFormStrains(
        ['Solo Strain'],
        ['0.3527'],
        'oz',
        $singleStrainRoundTrip,
    )->toPayload();
    if (($singleStrainPayload['strains'] ?? null) !== ['Solo Strain']
        || ($singleStrainPayload['strainAmountsG'] ?? null) !== [10.0]
        || ($singleStrainImperial['strain_amounts'] ?? null) !== [0.3527]
        || ($singleStrainImperial['start_amount'] ?? null) !== 0.3527
        || ($singleStrainEdited['strainAmountsG'] ?? null) !== [10.0]) {
        throw new RuntimeException('A single-strain template did not preserve its starting amount.');
    }
    $legacyPayload = $templatePayload;
    $legacyPayload['schemaVersion'] = 1;
    unset($legacyPayload['passes'], $legacyPayload['strains'], $legacyPayload['strainAmountsG']);
    $expandedLegacy = BatchTemplateData::fromPayload($legacyPayload)->toPayload();
    if (!is_array($expandedLegacy['passes'] ?? null) || count($expandedLegacy['passes']) !== 2
        || ($expandedLegacy['strains'] ?? null) !== []
        || ($expandedLegacy['strainAmountsG'] ?? null) !== []) {
        throw new RuntimeException('A schema-v1 template did not expand its historical pass count.');
    }
    $versionTwoPayload = $templatePayload;
    $versionTwoPayload['schemaVersion'] = 2;
    unset($versionTwoPayload['strains'], $versionTwoPayload['strainAmountsG']);
    $expandedVersionTwo = BatchTemplateData::fromPayload($versionTwoPayload)->toPayload();
    if (count($expandedVersionTwo['passes'] ?? []) !== 2
        || ($expandedVersionTwo['strains'] ?? null) !== []
        || ($expandedVersionTwo['strainAmountsG'] ?? null) !== []) {
        throw new RuntimeException('A schema-v2 template did not retain its passes without strain data.');
    }
    try {
        BatchTemplateData::fromPayload($templatePayload)->withFormStrains(
            ['Overflow One', 'Overflow Two'],
            ['1000001', '1'],
            'g',
        );
        throw new RuntimeException('Overflow-sized template strain amounts were accepted.');
    } catch (ValidationException) {
        // Expected: owner-submitted values cannot overflow later template rendering.
    }
    if ($database->query("SELECT value FROM settings WHERE key = 'accent'")->fetchColumn() !== 'green') {
        throw new RuntimeException('The accent preference was not persisted.');
    }
    if ($database->query(
        "SELECT value FROM settings WHERE key = 'analytics_default_material'"
    )->fetchColumn() !== 'Flower') {
        throw new RuntimeException('The default analytics material was not persisted canonically.');
    }
    $authentication = new AuthenticationRepository($database);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $authentication->recordSensitiveFailure();
    }
    if ($authentication->sensitiveOperationBlockedFor() < 1) {
        throw new RuntimeException('Sensitive authentication attempts were not throttled.');
    }
    $authentication->clearSensitiveFailures();
    if ($database->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
        || $database->query('PRAGMA foreign_key_check')->fetch() !== false) {
        throw new RuntimeException('The smoke-test database failed its integrity check.');
    }

    fwrite(STDOUT, "Rosin Tracker smoke test passed.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Smoke test failed: ' . $error->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if (is_dir($temporaryRoot)) {
        removeSmokeDirectory($temporaryRoot);
    }
}

exit($exitCode);
