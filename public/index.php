<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use DI\Container;
use Slim\Flash\Messages;
use App\Validator;
use App\UrlNormalizer;
use App\Connection;
use App\Repositories\UrlRepository as Url;
use App\Repositories\UrlCheckRepository as UrlCheck;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Carbon\Carbon;
use DiDom\Document;

session_start();


$container = new Container();


AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

$container->set('router', fn() => $app->getRouteCollector()->getRouteParser());


$container->set('renderer', function (Container $c) {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layout.phtml');
    $renderer->addAttribute('router', $c->get('router')); // Доступен во всех шаблонах
    return $renderer;
});

$container->set('flash', fn() => new Messages());

// Middleware для добавления flash-сообщений в шаблоны
$app->add(function ($request, $handler) {
    $this->get('renderer')->addAttribute('flashMessages', $this->get('flash')->getMessages());
    return $handler->handle($request);
});

// База данных и репозитории
$container->set('db', fn() => Connection::getInstance()->getConnection());
$container->set(Url::class, fn($c) => new Url($c->get('db')));
$container->set(UrlCheck::class, fn($c) => new UrlCheck($c->get('db')));


// Главная страница
$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'home.phtml');
})->setName('home');

// Добавление URL
$app->post('/urls', function ($request, $response) {
    $data = $request->getParsedBody();
    $urlName = mb_strtolower(trim($data['url']['name'] ?? ''));
    $errors = Validator::validate($urlName);

    if (!empty($errors)) {
        return $this->get('renderer')->render($response->withStatus(422), 'home.phtml', [
            'errors' => $errors,
            'urlName' => $urlName,
        ]);
    }

    $parsed = parse_url($urlName);
    $scheme = mb_strtolower($parsed['scheme']);
    $host = mb_strtolower($parsed['host']);
    $normalizedUrl = "$scheme://$host";

    $urlRepository = $this->get(Url::class);
    $existingUrl = $urlRepository->findByName($normalizedUrl);

    if ($existingUrl) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withRedirect(
            $this->get('router')->urlFor('urls.show', ['id' => (string)$existingUrl['id']])
        );
    }

    $urlId = $urlRepository->insert($normalizedUrl);
    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
     return $response->withRedirect(
         $this->get('router')->urlFor('urls.show', ['id' => $urlId])
     );
})->setName('urls.store');

// Список URL
$app->get('/urls', function ($request, $response) {

    $urlRepository = $this->get(Url::class);
    $checkUrl = $this->get(UrlCheck::class);

    $urls = $urlRepository->getAll();
    $checks = $checkUrl->getLatestChecks();

    $checksByUrlId = [];
    foreach ($checks as $check) {
        $checksByUrlId[$check['url_id']] = $check;
    }

    $urlsWithChecks = array_map(function ($url) use ($checksByUrlId) {
        $check = $checksByUrlId[$url['id']] ?? null;
        return [
            'id' => $url['id'],
            'name' => $url['name'],
            'created_at' => $url['created_at'],
            'last_check' => $check['created_at'] ?? null,
            'response_code' => $check['status_code'] ?? null,
        ];
    }, $urls);

    return $this->get('renderer')->render($response, 'urls/index.phtml', [
        'urls' => $urlsWithChecks,
    ]);
})->setName('urls.index');

// Детали конкретного URL
$app->get('/urls/{id:[0-9]+}', function ($request, $response, $args) {

    $urlRepository = $this->get(Url::class);
    $checkUrl = $this->get(UrlCheck::class);

    $id = (int)$args['id'];
    $url = $urlRepository->find($id);

    if (!$url) {
        return $this->get('renderer')->render($response->withStatus(404), 'errors/404.phtml');
    }

    $checks = $checkUrl->findByUrlId($id);

     return $this->get('renderer')->render($response, 'urls/show.phtml', [
        'url' => $url,
        'checks' => $checks,
     ]);
})->setName('urls.show');

// Создание проверки URL
$app->post('/urls/{url_id}/checks', function ($request, $response, $args) {
    $urlId = (int)$args['url_id'];

    $urlRepository = $this->get(Url::class);
    $checkUrl = $this->get(UrlCheck::class);

    $url = $urlRepository->find($urlId);
    if (!$url) {
        $response->getBody()->write('URL не найден');
        return $response->withStatus(404);
    }

    $client = new Client(['timeout' => 10.0]);
    $now = Carbon::now();

    try {
        $res = $client->request('GET', $url['name']);
        $statusCode = $res->getStatusCode();
        $html = $res->getBody()->getContents();

        $document = new Document($html);
        $h1 = optional($document->first('h1'))->text();
        $title = optional($document->first('title'))->text();
        $description = $document->first('meta[name=description]')?->getAttribute('content');

        $checkUrl->insert([
            ':url_id' => $urlId,
            ':status_code' => $statusCode,
            ':h1' => $h1,
            ':title' => $title,
            ':description' => $description,
            ':created_at' => $now
        ]);

        $this->get('flash')->addMessage('success', "Страница успешно проверена");
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');
    } catch (RequestException $e) {
        $statusCode = $e->getResponse()?->getStatusCode();

        if ($statusCode !== null) {
            $checkUrl->insert([
                ':url_id' => $urlId,
                ':status_code' => $statusCode,
                ':h1' => null,
                ':title' => null,
                ':description' => null,
                ':created_at' => $now
            ]);
            $this->get('flash')->addMessage('error', "Ошибка ответа. Код: $statusCode");
        } else {
            $this->get('flash')->addMessage('error', 'Ошибка запроса. Код ответа отсутствует.');
        }
    }

     return $response->withRedirect(
         $this->get('router')->urlFor('urls.show', ['id' => (string)$urlId])
     );
})->setName('urls.checks.store');

$app->run();
