<?php

declare(strict_types=1);

$name = isset($providerName) && is_string($providerName) && trim($providerName) !== ''
    ? trim($providerName)
    : 'OpenID provider';
$target = isset($authorizationUrl) && is_string($authorizationUrl) ? $authorizationUrl : '';
$csrf = isset($csrfToken) && is_string($csrfToken) ? $csrfToken : '';
?>
<div class="auth-heading auth-heading--compact">
  <h1 id="auth-title">Continue to <?= $view->escape($name) ?></h1>
</div>

<div class="auth-form">
  <a class="button button--primary button--large button--full" href="<?= $view->escape($target) ?>" rel="noreferrer" data-oidc-continue>Continue<svg aria-hidden="true"><use href="<?= $view->escape($view->asset('/assets/icons.svg')) ?>#arrow-right"></use></svg></a>
  <form action="/auth/oidc/cancel" method="post"><input type="hidden" name="_csrf" value="<?= $view->escape($csrf) ?>"><button class="button button--ghost button--full" type="submit">Cancel</button></form>
</div>
