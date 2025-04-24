<?php

require __DIR__ . '/../vendor/autoload.php';


$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use DI\Container;
use Slim\Flash\Messages;
use App\Validator;
use App\Connect;

session_start();

// Настройка контейнера
$container = new Container();

// Рендерер шаблонов
$container->set('renderer', function () {
    return new PhpRenderer(__DIR__ . '/../templates');
});

// Flash-сообщения
$container->set('flash', function () {
    return new Messages();
});

// Подключение к базе данных через класс Connect
$container->set('db', function () {
    return Connect::getInstance()->getConnection();
});
//\App\Validator::validate($urlName);
// Создание приложения с DI
AppFactory::setContainer($container);
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

$router = $app->getRouteCollector()->getRouteParser();

// Главная страница с формой
$app->get('/', function ($request, $response) use ($router) {
    return $this->get('renderer')->render($response, 'main.phtml', [
        'flashMessages' => $this->get('flash')->getMessages(),
        'urlName' => ''
    ]);
})->setName('home');

// Обработчик добавления URL
$app->post('/urls', function ($request, $response) use ($router) {
    $data = $request->getParsedBody();
    $urlName = trim($data['url']['name']);

    $errors = Validator::validate($urlName);

    $db = $this->get('db');

    $stmt = $db->prepare("SELECT COUNT(*) FROM urls WHERE name = :name");
    $stmt->bindParam(':name', $urlName);
    $stmt->execute();
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        $errors[] = 'Этот URL уже существует.';
    }

    if (!empty($errors)) {
        foreach ($errors as $error) {
            $this->get('flash')->addMessage('error', $error);
        }

        return $this->get('renderer')->render($response, 'main.phtml', [
            'flashMessages' => $this->get('flash')->getMessages(),
            'urlName' => $urlName
        ]);
    }

    $stmt = $db->prepare("INSERT INTO urls (name) VALUES (:name)");
    $stmt->bindParam(':name', $urlName);
    $stmt->execute();

    $this->get('flash')->addMessage('success', 'URL успешно добавлен!');
    return $response->withHeader('Location', $router->urlFor('list_urls'))->withStatus(302);
})->setName('add_url');

// Отображение всех URL
$app->get('/urls', function ($request, $response) {
    $db = $this->get('db');

    $stmt = $db->query("SELECT * FROM urls ORDER BY id DESC");
    $urls = $stmt->fetchAll();

    return $this->get('renderer')->render($response, 'urls.phtml', [
        'urls' => $urls,
        'flashMessages' => $this->get('flash')->getMessages()
    ]);
})->setName('list_urls');

// Отображение конкретного URL
$app->get('/urls/{id}', function ($request, $response, $args) {
    $db = $this->get('db');

    $id = (int) $args['id'];

    $stmt = $db->prepare("SELECT * FROM urls WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $url = $stmt->fetch();

    if (!$url) {
        return $response->withStatus(404)->write('URL not found');
    }

    return $this->get('renderer')->render($response, 'url.phtml', [
        'url' => $url
    ]);
});

// Запуск приложения
$app->run();
