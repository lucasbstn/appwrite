<?php

require_once __DIR__.'/../init.php';

use Utopia\App;
use Utopia\Logger\Log;
use Utopia\Logger\Log\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\View;
use Appwrite\Extend\Exception;
use Utopia\Config\Config;
use Utopia\Domains\Domain;
use Appwrite\Auth\Auth;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Response\Filters\V11 as ResponseV11;
use Appwrite\Utopia\Response\Filters\V12 as ResponseV12;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Appwrite\Utopia\Request\Filters\V12 as RequestV12;
use Appwrite\Utopia\Request\Filters\V13 as RequestV13;
use Utopia\Validator\Text;

Config::setParam('domainVerification', false);
Config::setParam('cookieDomain', 'localhost');
Config::setParam('cookieSamesite', Response::COOKIE_SAMESITE_NONE);

App::init(function ($utopia, $request, $response, $console, $project, $dbForConsole, $user, $locale, $clients) {
    /** @var Utopia\App $utopia */
    /** @var Appwrite\Utopia\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Utopia\Database\Document $console */
    /** @var Utopia\Database\Document $project */
    /** @var Utopia\Database\Database $dbForConsole */
    /** @var Utopia\Database\Document $user */
    /** @var Utopia\Locale\Locale $locale */
    /** @var array $clients */

    /*
     * Request format
    */
    $route = $utopia->match($request);
    Request::setRoute($route);

    $requestFormat = $request->getHeader('x-appwrite-response-format', App::getEnv('_APP_SYSTEM_RESPONSE_FORMAT', ''));
    if ($requestFormat) {
        switch($requestFormat) {
            case version_compare ($requestFormat , '0.12.0', '<') :
                Request::setFilter(new RequestV12());
                break;
            case version_compare ($requestFormat , '0.13.0', '<') :
                Request::setFilter(new RequestV13());
                break;
            default:
                Request::setFilter(null);
        }
    } else {
        Request::setFilter(null);
    }

    $domain = $request->getHostname();
    $domains = Config::getParam('domains', []);
    if (!array_key_exists($domain, $domains)) {
        $domain = new Domain(!empty($domain) ? $domain : '');

        if (empty($domain->get()) || !$domain->isKnown() || $domain->isTest()) {
            $domains[$domain->get()] = false;
            Console::warning($domain->get() . ' is not a publicly accessible domain. Skipping SSL certificate generation.');
        } elseif(str_starts_with($request->getURI(), '/.well-known/acme-challenge')) {
            Console::warning('Skipping SSL certificates generation on ACME challenge.');
        } else {
            Authorization::disable();

            $domainDocument = $dbForConsole->findOne('domains', [
                new Query('domain', QUERY::TYPE_EQUAL, [$domain->get()])
            ]);

            if (!$domainDocument) {
                $domainDocument = new Document([
                    'domain' => $domain->get(),
                    'tld' => $domain->getSuffix(),
                    'registerable' => $domain->getRegisterable(),
                    'verification' => false,
                    'certificateId' => null,
                ]);

                $domainDocument = $dbForConsole->createDocument('domains', $domainDocument);

                Console::info('Issuing a TLS certificate for the master domain (' . $domain->get() . ') in a few seconds...');

                Resque::enqueue('v1-certificates', 'CertificatesV1', [
                    'document' => $domainDocument,
                    'domain' => $domain->get(),
                    'validateTarget' => false,
                    'validateCNAME' => false,
                ]);
            }

            $domains[$domain->get()] = true;

            Authorization::reset(); // ensure authorization is re-enabled
        }
        Config::setParam('domains', $domains);
    }

    $localeParam = (string) $request->getParam('locale', $request->getHeader('x-appwrite-locale', ''));
    if (\in_array($localeParam, Config::getParam('locale-codes'))) {
        $locale->setDefault($localeParam);
    }

    if ($project->isEmpty()) {
        throw new Exception('Project not found', 404, Exception::PROJECT_NOT_FOUND);
    }

    if (!empty($route->getLabel('sdk.auth', [])) && $project->isEmpty() && ($route->getLabel('scope', '') !== 'public')) {
        throw new Exception('Missing or unknown project ID', 400, Exception::PROJECT_UNKNOWN);
    }

    $referrer = $request->getReferer();
    $origin = \parse_url($request->getOrigin($referrer), PHP_URL_HOST);
    $protocol = \parse_url($request->getOrigin($referrer), PHP_URL_SCHEME);
    $port = \parse_url($request->getOrigin($referrer), PHP_URL_PORT);

    $refDomain = (!empty($protocol) ? $protocol : $request->getProtocol()).'://'.((\in_array($origin, $clients))
        ? $origin : 'localhost').(!empty($port) ? ':'.$port : '');

    $refDomain = (!$route->getLabel('origin', false))  // This route is publicly accessible
        ? $refDomain
        : (!empty($protocol) ? $protocol : $request->getProtocol()).'://'.$origin.(!empty($port) ? ':'.$port : '');

    $selfDomain = new Domain($request->getHostname());
    $endDomain = new Domain((string)$origin);

    Config::setParam('domainVerification',
        ($selfDomain->getRegisterable() === $endDomain->getRegisterable()) &&
            $endDomain->getRegisterable() !== '');

    Config::setParam('cookieDomain', (
        $request->getHostname() === 'localhost' ||
        $request->getHostname() === 'localhost:'.$request->getPort() ||
        (\filter_var($request->getHostname(), FILTER_VALIDATE_IP) !== false)
    )
        ? null
        : '.'.$request->getHostname()
    );

    /* 
     * Response format
     */
    $responseFormat = $request->getHeader('x-appwrite-response-format', App::getEnv('_APP_SYSTEM_RESPONSE_FORMAT', ''));
    if ($responseFormat) {
        switch($responseFormat) {
            case version_compare ($responseFormat , '0.11.2', '<=') :
                Response::setFilter(new ResponseV11());
                break;
            case version_compare ($responseFormat , '0.12.4', '<='):
                Response::setFilter(new ResponseV12());
                break;
            default:
                Response::setFilter(null);
        }
    } else {
        Response::setFilter(null);
    }

    /*
     * Security Headers
     *
     * As recommended at:
     * @see https://www.owasp.org/index.php/List_of_useful_HTTP_headers
     */
    if (App::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled') === 'enabled') { // Force HTTPS
        if ($request->getProtocol() !== 'https') {
            return $response->redirect('https://'.$request->getHostname().$request->getURI());
        }

        $response->addHeader('Strict-Transport-Security', 'max-age='.(60 * 60 * 24 * 126)); // 126 days
    }

    $response
        ->addHeader('Server', 'Appwrite')
        ->addHeader('X-Content-Type-Options', 'nosniff')
        ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
        ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-Appwrite-JWT, X-Appwrite-Response-Format, X-SDK-Version, X-Appwrite-ID, Content-Range, Range, Cache-Control, Expires, Pragma')
        ->addHeader('Access-Control-Expose-Headers', 'X-Fallback-Cookies')
        ->addHeader('Access-Control-Allow-Origin', $refDomain)
        ->addHeader('Access-Control-Allow-Credentials', 'true')
    ;

    /*
     * Validate Client Domain - Check to avoid CSRF attack
     *  Adding Appwrite API domains to allow XDOMAIN communication
     *  Skip this check for non-web platforms which are not required to send an origin header
     */
    $origin = $request->getOrigin($request->getReferer(''));
    $originValidator = new Origin(\array_merge($project->getAttribute('platforms', []), $console->getAttribute('platforms', [])));

    if (!$originValidator->isValid($origin)
        && \in_array($request->getMethod(), [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_PATCH, Request::METHOD_DELETE])
        && $route->getLabel('origin', false) !== '*'
        && empty($request->getHeader('x-appwrite-key', ''))) {
        throw new Exception($originValidator->getDescription(), 403, Exception::GENERAL_UNKNOWN_ORIGIN);
    }

    /*
     * ACL Check
     */
    $role = ($user->isEmpty()) ? Auth::USER_ROLE_GUEST : Auth::USER_ROLE_MEMBER;

    // Add user roles
    $memberships = $user->find('teamId', $project->getAttribute('teamId', null), 'memberships');

    if ($memberships) {
        foreach ($memberships->getAttribute('roles', []) as $memberRole) {
            switch ($memberRole) {
                case 'owner':
                    $role = Auth::USER_ROLE_OWNER;
                    break;
                case 'admin':
                    $role = Auth::USER_ROLE_ADMIN;
                    break;
                case 'developer':
                    $role = Auth::USER_ROLE_DEVELOPER;
                    break;
            }
        }
    }

    $roles = Config::getParam('roles', []);
    $scope = $route->getLabel('scope', 'none'); // Allowed scope for chosen route
    $scopes = $roles[$role]['scopes']; // Allowed scopes for user role

    $authKey = $request->getHeader('x-appwrite-key', '');

    if (!empty($authKey)) { // API Key authentication
        // Check if given key match project API keys
        $key = $project->find('secret', $authKey, 'keys');

        /*
         * Try app auth when we have project key and no user
         *  Mock user to app and grant API key scopes in addition to default app scopes
         */
        if ($key && $user->isEmpty()) {
            $user = new Document([
                '$id' => '',
                'status' => true,
                'email' => 'app.'.$project->getId().'@service.'.$request->getHostname(),
                'password' => '',
                'name' => $project->getAttribute('name', 'Untitled'),
            ]);

            $role = Auth::USER_ROLE_APP;
            $scopes = \array_merge($roles[$role]['scopes'], $key->getAttribute('scopes', []));

            Authorization::setRole('role:'.Auth::USER_ROLE_APP);
            Authorization::setDefaultStatus(false);  // Cancel security segmentation for API keys.
        }
    }

    Authorization::setRole('role:'.$role);

    foreach (Auth::getRoles($user) as $authRole) {
        Authorization::setRole($authRole);
    }

    $service = $route->getLabel('sdk.namespace','');
    if(!empty($service)) {
        if(array_key_exists($service, $project->getAttribute('services',[]))
            && !$project->getAttribute('services',[])[$service]
            && !Auth::isPrivilegedUser(Authorization::getRoles())) {
            throw new Exception('Service is disabled', 503, Exception::GENERAL_SERVICE_DISABLED);
        }
    }

    if (!\in_array($scope, $scopes)) {
        if ($project->isEmpty()) { // Check if permission is denied because project is missing
            throw new Exception('Project not found', 404, Exception::PROJECT_NOT_FOUND);
        }

        throw new Exception($user->getAttribute('email', 'User').' (role: '.\strtolower($roles[$role]['label']).') missing scope ('.$scope.')', 401, Exception::GENERAL_UNAUTHORIZED_SCOPE);
    }

    if (false === $user->getAttribute('status')) { // Account is blocked
        throw new Exception('Invalid credentials. User is blocked', 401, Exception::USER_BLOCKED);
    }

    if ($user->getAttribute('reset')) {
        throw new Exception('Password reset is required', 412, Exception::USER_PASSWORD_RESET_REQUIRED);
    }

}, ['utopia', 'request', 'response', 'console', 'project', 'dbForConsole', 'user', 'locale', 'clients']);

App::options(function ($request, $response) {
    /** @var Appwrite\Utopia\Request $request */
    /** @var Appwrite\Utopia\Response $response */

    $origin = $request->getOrigin();

    $response
        ->addHeader('Server', 'Appwrite')
        ->addHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE')
        ->addHeader('Access-Control-Allow-Headers', 'Origin, Cookie, Set-Cookie, X-Requested-With, Content-Type, Access-Control-Allow-Origin, Access-Control-Request-Headers, Accept, X-Appwrite-Project, X-Appwrite-Key, X-Appwrite-Locale, X-Appwrite-Mode, X-Appwrite-JWT, X-Appwrite-Response-Format, X-SDK-Version, X-Appwrite-ID, Content-Range, Range, Cache-Control, Expires, Pragma, X-Fallback-Cookies')
        ->addHeader('Access-Control-Expose-Headers', 'X-Fallback-Cookies')
        ->addHeader('Access-Control-Allow-Origin', $origin)
        ->addHeader('Access-Control-Allow-Credentials', 'true')
        ->noContent();
}, ['request', 'response']);

App::error(function ($error, $utopia, $request, $response, $layout, $project, $logger, $loggerBreadcrumbs) {
    /** @var Exception $error */
    /** @var Utopia\App $utopia */
    /** @var Appwrite\Utopia\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Appwrite\Utopia\View $layout */
    /** @var Utopia\Database\Document $project */
    /** @var Utopia\Logger\Logger $logger */
    /** @var Utopia\Logger\Log\Breadcrumb[] $loggerBreadcrumbs */

    $version = App::getEnv('_APP_VERSION', 'UNKNOWN');
    $route = $utopia->match($request);

    /** Delegate PDO exceptions to the global handler so the database connection can be returned to the pool */
    if ($error instanceof PDOException) {
        throw $error;
    }

    if($logger) {
        if($error->getCode() >= 500 || $error->getCode() === 0) {
            try {
                /** @var Utopia\Database\Document $user */
                $user = $utopia->getResource('user');
            } catch(\Throwable $th) {
                // All good, user is optional information for logger
            }

            $log = new Utopia\Logger\Log();

            if(isset($user) && !$user->isEmpty()) {
                $log->setUser(new User($user->getId()));
            }

            $log->setNamespace("http");
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());

            $log->addTag('method', $route->getMethod());
            $log->addTag('url',  $route->getPath());
            $log->addTag('verboseType', get_class($error));
            $log->addTag('code', $error->getCode());
            $log->addTag('projectId', $project->getId());
            $log->addTag('hostname', $request->getHostname());
            $log->addTag('locale', (string)$request->getParam('locale', $request->getHeader('x-appwrite-locale', '')));

            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('detailedTrace', $error->getTrace());
            $log->addExtra('roles', Authorization::$roles);

            $action = $route->getLabel("sdk.namespace", "UNKNOWN_NAMESPACE") . '.' . $route->getLabel("sdk.method", "UNKNOWN_METHOD");
            $log->setAction($action);

            $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            foreach($loggerBreadcrumbs as $loggerBreadcrumb) {
                $log->addBreadcrumb($loggerBreadcrumb);
            }

            $responseCode = $logger->addLog($log);
            Console::info('Log pushed with status code: '.$responseCode);
        }
    }

    $code = $error->getCode();
    $message = $error->getMessage();
    $file = $error->getFile();
    $line = $error->getLine();
    $trace = $error->getTrace();

    if (php_sapi_name() === 'cli') {
        Console::error('[Error] Timestamp: '.date('c', time()));

        if($route) {
            Console::error('[Error] Method: '.$route->getMethod());
            Console::error('[Error] URL: '.$route->getPath());
        }

        Console::error('[Error] Type: '.get_class($error));
        Console::error('[Error] Message: '.$message);
        Console::error('[Error] File: '.$file);
        Console::error('[Error] Line: '.$line);
    }

    /** Handle Utopia Errors */
    if ($error instanceof Utopia\Exception) {
        $error = new Exception($message, $code, Exception::GENERAL_UNKNOWN, $error);
        switch($code) {
            case 400:
                $error->setType(Exception::GENERAL_ARGUMENT_INVALID);
                break;
            case 404:
                $error->setType(Exception::GENERAL_ROUTE_NOT_FOUND);
                break;
        }
    }

    /** Wrap all exceptions inside Appwrite\Extend\Exception */
    if (!($error instanceof Exception)) {
        $error = new Exception($message, $code, Exception::GENERAL_UNKNOWN, $error);
    }

    switch ($code) { // Don't show 500 errors!
        case 400: // Error allowed publicly
        case 401: // Error allowed publicly
        case 402: // Error allowed publicly
        case 403: // Error allowed publicly
        case 404: // Error allowed publicly
        case 409: // Error allowed publicly
        case 412: // Error allowed publicly
        case 416: // Error allowed publicly
        case 429: // Error allowed publicly
        case 501: // Error allowed publicly
        case 503: // Error allowed publicly
            break;
        default:
            $code = 500; // All other errors get the generic 500 server error status code
            $message = 'Server Error';
    }

    //$_SERVER = []; // Reset before reporting to error log to avoid keys being compromised

    $type = $error->getType();

    $output = ((App::isDevelopment())) ? [
        'message' => $message,
        'code' => $code,
        'file' => $file,
        'line' => $line,
        'trace' => $trace,
        'version' => $version,
        'type' => $type,
    ] : [
        'message' => $message,
        'code' => $code,
        'version' => $version,
        'type' => $type,
    ];

    $response
        ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->addHeader('Expires', '0')
        ->addHeader('Pragma', 'no-cache')
        ->setStatusCode($code)
    ;

    $template = ($route) ? $route->getLabel('error', null) : null;

    if ($template) {
        $comp = new View($template);

        $comp
            ->setParam('development', App::isDevelopment())
            ->setParam('projectName', $project->getAttribute('name'))
            ->setParam('projectURL', $project->getAttribute('url'))
            ->setParam('message', $error->getMessage())
            ->setParam('code', $code)
            ->setParam('trace', $trace)
        ;

        $layout
            ->setParam('title', $project->getAttribute('name').' - Error')
            ->setParam('description', 'No Description')
            ->setParam('body', $comp)
            ->setParam('version', $version)
            ->setParam('litespeed', false)
        ;

        $response->html($layout->render());
    }

    $response->dynamic(new Document($output),
        $utopia->isDevelopment() ? Response::MODEL_ERROR_DEV : Response::MODEL_ERROR);
}, ['error', 'utopia', 'request', 'response', 'layout', 'project', 'logger', 'loggerBreadcrumbs']);

App::get('/manifest.json')
    ->desc('Progressive app manifest file')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->json([
            'name' => APP_NAME,
            'short_name' => APP_NAME,
            'start_url' => '.',
            'url' => 'https://appwrite.io/',
            'display' => 'standalone',
            'background_color' => '#fff',
            'theme_color' => '#f02e65',
            'description' => 'End to end backend server for frontend and mobile apps. 👩‍💻👨‍💻',
            'icons' => [
                [
                    'src' => 'images/favicon.png',
                    'sizes' => '256x256',
                    'type' => 'image/png',
                ],
            ],
        ]);
    });

App::get('/robots.txt')
    ->desc('Robots.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function ($response) {
        $template = new View(__DIR__.'/../views/general/robots.phtml');
        $response->text($template->render(false));
    });

App::get('/humans.txt')
    ->desc('Humans.txt File')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function ($response) {
        $template = new View(__DIR__.'/../views/general/humans.phtml');
        $response->text($template->render(false));
    });

App::get('/.well-known/assetlinks.json')
    ->desc('Assetlinks file')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->json([
            [
             'relation' => ["delegate_permission/common.handle_all_urls"],
             'target' => [
                    "namespace" => "android_app",
                    "package_name" => "app.pilotdev.flyclub",
                    "sha256_cert_fingerprints" => [
                        "F0:BB:6C:7A:EE:07:0F:29:73:92:98:36:FE:3A:C8:F4:F8:1D:D5:CE:30:E3:F5:BA:C7:BE:69:88:44:FC:C6:82"
                    ]
             ]
            ],
        ]);
    });

App::get('/.well-known/apple-app-site-association')
    ->desc('Apple app site association file')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('response')
    ->action(function ($response) {
        /** @var Appwrite\Utopia\Response $response */

        $response->json([
             'applinks' => [
                 'apps' => [],
                 'details' => [
                     'appID' => '8S44L77Q6Z.app.pilotdev.flyclub',
                     'paths' => [
                         '/invite'
                     ]
                ]
             ],
        ]);
    });

    
App::get('/.well-known/acme-challenge')
    ->desc('SSL Verification')
    ->label('scope', 'public')
    ->label('docs', false)
    ->inject('request')
    ->inject('response')
    ->action(function ($request, $response) {
        $uriChunks = \explode('/', $request->getURI());
        $token = $uriChunks[\count($uriChunks) - 1];

        $validator = new Text(100, [
            ...Text::NUMBERS,
            ...Text::ALPHABET_LOWER,
            ...Text::ALPHABET_UPPER,
            '-',
            '_'
        ]);

        if (!$validator->isValid($token) || \count($uriChunks) !== 4) {
            throw new Exception('Invalid challenge token.', 400);
        }

        $base = \realpath(APP_STORAGE_CERTIFICATES);
        $absolute = \realpath($base.'/.well-known/acme-challenge/'.$token);

        if (!$base) {
            throw new Exception('Storage error', 500, Exception::GENERAL_SERVER_ERROR);
        }

        if (!$absolute) {
            throw new Exception('Unknown path', 404);
        }

        if (!\substr($absolute, 0, \strlen($base)) === $base) {
            throw new Exception('Invalid path', 401);
        }

        if (!\file_exists($absolute)) {
            throw new Exception('Unknown path', 404);
        }

        $content = @\file_get_contents($absolute);

        if (!$content) {
            throw new Exception('Failed to get contents', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $response->text($content);
    });

include_once __DIR__ . '/shared/api.php';
include_once __DIR__ . '/shared/web.php';

foreach (Config::getParam('services', []) as $service) {
    include_once $service['controller'];
}