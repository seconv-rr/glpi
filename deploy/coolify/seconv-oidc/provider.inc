# Single sign-on through Zitadel, done by Apache instead of GLPI.
#
# GLPI 11 has no OpenID Connect client. What it does have is "other authentication sent in
# the HTTP request": it trusts a server variable as the login and opens the session for that
# user (Auth::getAlternateAuthSystemsUserLogin(), src/Auth.php:575). mod_auth_openidc runs
# the whole OIDC exchange before PHP starts and leaves the result in REMOTE_USER, so GLPI
# never sees a password or a token.
#
# This file only says how to talk to Zitadel and protects nothing. on.conf and optional.conf
# include it and decide who has to log in. It is a .inc so that no value of SECONV_OIDC can
# make zzz-seconv-oidc.conf load it by itself.

OIDCProviderMetadataURL ${OIDC_ISSUER}/.well-known/openid-configuration
OIDCClientID ${OIDC_CLIENT_ID}
OIDCClientSecret ${OIDC_CLIENT_SECRET}
OIDCCryptoPassphrase ${OIDC_CRYPTO_PASSPHRASE}
OIDCPKCEMethod S256
OIDCScope "openid profile email"

# Relative on purpose: the instance answers on more than one domain, and each one has to be
# sent back to itself. Nothing exists at this path, the module answers it.
OIDCRedirectURI /oidc/callback

# GLPI keeps a single SSO logout URL and only accepts it absolute, so it names one domain. The
# module's session cookie belongs to the domain that set it: logging out through another domain
# would end the Zitadel session and leave the local one alive, still logged in. This points
# GLPI's redirect to /oidc/callback?logout= back at the domain the browser is on, for both the
# callback and the address Zitadel returns to.
Header set Location "expr=https://%{HTTP_HOST}/oidc/callback?logout=https%3A%2F%2F%{HTTP_HOST}%2F" "expr=resp('Location') =~ m#^https://[^/]+/oidc/callback\?logout=#"

# TLS ends at Coolify's Traefik and Apache only ever sees plain HTTP on port 80. Without
# these the module would build http:// URLs for the redirect and Zitadel would refuse them.
OIDCXForwardedHeaders X-Forwarded-Host X-Forwarded-Proto

# The claim that becomes the GLPI login. Zitadel's preferred_username is the login name,
# which may carry an @domain suffix; GLPI can strip it ("Remove the domain of logins").
OIDCRemoteUserClaim preferred_username

# Every claim reaches PHP twice: as a request header (HTTP_OIDC_CLAIM_..., what the GLPI
# authentication settings read) and as an environment variable (OIDC_CLAIM_...). The oidcroles
# plugin reads the roles from the environment copy, which no client can send. "both" is the
# module's default today; it is spelled out because the plugin stops working without it.
OIDCPassClaimsAs both
