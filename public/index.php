<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use DI\Container;
use Slim\Flash\Messages;
use App\Validator;
use App\Connect;
use App\Database\Url;
use App\Database\UrlCheck;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Carbon\Carbon;
use DiDom\Document;

session_start();

// Контейнер
$container = new Container();

// Рендерер
$container->set('renderer', function () {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layout.phtml');
    return $renderer;
});

// Flash-сообщения
$container->set('flash', fn() => new Messages());

// БД и DAO
$container->set('db', fn() => Connect::getInstance()->getConnection());
$container->set(Url::class, fn($c) => new Url($c->get('db')));
$container->set(UrlCheck::class, fn($c) => new UrlCheck($c->get('db')));

// Приложение
AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

// Главная страница
$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'home.phtml', [
        'flashMessages' => $this->get('flash')->getMessages(),
        'urlName' => '',
    ]);
})->setName('home');

// Добавление URL
$app->post('/urls', function ($request, $response) use ($router) {
    $data = $request->getParsedBody();
    $urlName = trim($data['url']['name']);
    $errors = Validator::validate($urlName);

    if (!empty($errors)) {
        return $this->get('renderer')->render($response->withStatus(422), 'home.phtml', [
            'errors' => $errors,
            'urlName' => $urlName
        ]);
    }

    $urlModel = $this->get(Url::class);
    $existingUrl = $urlModel->findByName($urlName);

    if ($existingUrl) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response
            ->withHeader('Location', $router->urlFor('url_details', ['id' => $existingUrl['id']]))
            ->withStatus(302);
    }

    $urlId = $urlModel->insert($urlName);
    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    return $response
        ->withHeader('Location', $router->urlFor('url_details', ['id' => $urlId]))
        ->withStatus(302);
})->setName('add_url');

// Список URL
$app->get('/urls', function ($request, $response) {

    $urlModel = $this->get(Url::class);
    $checkModel = $this->get(UrlCheck::class);

    $urls = $urlModel->getAll();
    $checks = $checkModel->getLatestChecks();

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
        'flashMessages' => $this->get('flash')->getMessages()
    ]);
})->setName('list_urls');

// Детали конкретного URL
$app->get('/urls/{id}', function ($request, $response, $args) {

    $urlModel = $this->get(Url::class);
    $checkModel = $this->get(UrlCheck::class);

    $id = (int)$args['id'];
    $url = $urlModel->find($id);

    if (!$url) {
        return $this->get('renderer')->render($response->withStatus(404), 'errors/404.phtml');
    }

    $checks = $checkModel->findByUrlId($id);

    return $this->get('renderer')->render($response, 'urls/show.phtml', [
        'url' => $url,
        'checks' => $checks,
        'flashMessages' => $this->get('flash')->getMessages()
    ]);
})->setName('url_details');

// Создание проверки URL
$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($router) {
    $urlId = (int)$args['url_id'];

    $urlModel = $this->get(Url::class);
    $checkModel = $this->get(UrlCheck::class);

    $url = $urlModel->find($urlId);
    if (!$url) {
        return $response->withStatus(404)->write('URL не найден');
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

        $checkModel->insert([
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
            $checkModel->insert([
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

    return $response
        ->withHeader('Location', $router->urlFor('url_details', ['id' => $urlId]))
        ->withStatus(302);
})->setName('create_check');

$app->run();
