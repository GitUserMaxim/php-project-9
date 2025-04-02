<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use DI\Container;
use Slim\Flash\Messages as FlashMessages;

$container = new Container();
$container->set('renderer', function () {
    return new PhpRenderer(__DIR__ . '/../templates');
});

// Регистрация flash-сообщений в контейнере
$container->set('flash', function () {
    return new FlashMessages();
});

// Создание приложения
$app = AppFactory::createFromContainer($container);
$app->addErrorMiddleware(true, true, true);

// Подключение к базе данных
$dsn = 'pgsql:host=localhost;dbname=mydb;user=maxim;password=123456';
$db = new PDO($dsn);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Главная страница
$app->get('/', function ($request, $response) {
    return $this->get('renderer')->render($response, 'main.phtml');
});

// Обработчик для добавления URL
$app->post('/urls', function ($request, $response) use ($db) {
    $data = $request->getParsedBody();
    $urlName = $data['url']['name'];

    // Вставка URL в базу данных
    $stmt = $db->prepare("INSERT INTO urls (name) VALUES (:name)");
    $stmt->bindParam(':name', $urlName);
    $stmt->execute();

    // Перенаправление на главную страницу или страницу со списком URL
    return $response->withHeader('Location', '/')->withStatus(302);
});

// Обработчик для отображения всех URL
$app->get('/urls', function ($request, $response) use ($db) {
    // Извлечение всех URL из базы данных
    $stmt = $db->query("SELECT * FROM urls ORDER BY id DESC"); // Новые записи первыми
    $urls = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Передача данных в шаблон
    return $this->get('renderer')->render($response, 'urls.phtml', [
        'urls' => $urls,
        'flashMessages' => $this->get('flash')->getMessages()
    ]);
})->setName('urls');

// Обработчик для отображения конкретного URL
$app->get('/urls/{id}', function ($request, $response, $args) use ($db) {
    $id = $args['id'];

    // Извлечение URL по ID
    $stmt = $db->prepare("SELECT * FROM urls WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $url = $stmt->fetch(PDO::FETCH_ASSOC);

    // Проверка, существует ли URL
    if (!$url) {
        return $response->withStatus(404)->write('URL not found');
    }

    // Передача данных в шаблон
    return $this->get('renderer')->render($response, 'url.phtml', [
        'url' => $url
    ]);
});

// Запуск приложения
$app->run();