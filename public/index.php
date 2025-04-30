<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use DI\Container;
use Slim\Flash\Messages;
use App\Validator;
use App\Connect;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

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
    return \App\Connect::getInstance()->getConnection();
});

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

    $stmt = $db->prepare("SELECT id FROM urls WHERE name = :name");
    $stmt->bindParam(':name', $urlName);
    $stmt->execute();
    $existingUrl = $stmt->fetch();

    if ($existingUrl) {
        $this->get('flash')->addMessage('success', 'Страница уже существует');
        return $response->withHeader('Location', $router->urlFor('url_details', ['id' => $existingUrl['id']]))->withStatus(302);
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

    $this->get('flash')->addMessage('success', 'Страница успешно добавлена');
    return $response->withHeader('Location', $router->urlFor('list_urls'))->withStatus(302);
})->setName('add_url');

// Отображение всех URL
$app->get('/urls', function ($request, $response) {
    $db = $this->get('db');

    $sql = "SELECT urls.id, urls.name, urls.created_at,
    MAX(url_checks.created_at) AS last_check,
    MAX(url_checks.status_code) AS response_code
    FROM urls
    LEFT JOIN url_checks ON urls.id = url_checks.url_id
    GROUP BY urls.id, urls.name, urls.created_at
    ORDER BY urls.id DESC";

    $stmt = $db->query($sql);
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
        return $response->withStatus(404)->write('URL не найден');
    }

    // Получаем список проверок для данного URL
    $stmtChecks = $db->prepare("SELECT * FROM url_checks WHERE url_id = :url_id ORDER BY id DESC");
    $stmtChecks->bindParam(':url_id', $id, PDO::PARAM_INT);
    $stmtChecks->execute();
    $checks = $stmtChecks->fetchAll();

    return $this->get('renderer')->render($response, 'url.phtml', [
        'url' => $url,
        'checks' => $checks, // Передаем список проверок в шаблон
        'flashMessages' => $this->get('flash')->getMessages()
    ]);
})->setName('url_details');

// Обработчик создания новой проверки
$app->post('/urls/{url_id}/checks', function ($request, $response, $args) use ($router) {
    $urlId = (int) $args['url_id'];
    $db = $this->get('db');

    // Получение URL из БД
    $stmt = $db->prepare("SELECT * FROM urls WHERE id = :id");
    $stmt->bindParam(':id', $urlId, PDO::PARAM_INT);
    $stmt->execute();
    $url = $stmt->fetch();

    if (!$url) {
        return $response->withStatus(404)->write('URL не найден');
    }

    $client = new Client(['timeout' => 10.0]);
    $now = date('Y-m-d H:i:s');

    try {
        $res = $client->request('GET', $url['name']);
        $statusCode = $res->getStatusCode();

        $stmt = $db->prepare("
            INSERT INTO url_checks (url_id, status_code, created_at)
            VALUES (:url_id, :status_code, :created_at)
        ");
        $stmt->execute([
            ':url_id' => $urlId,
            ':status_code' => $statusCode,
            ':created_at' => $now
        ]);

        $this->get('flash')->addMessage('success', "Страница успешно проверена");
    } catch (ConnectException $e) {
        $this->get('flash')->addMessage('error', 'Произошла ошибка при проверке, не удалось подключиться');
    } catch (RequestException $e) {
        $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;

        if ($statusCode !== null) {
            // Даже при ошибке 404/500 сохраняем код
            $stmt = $db->prepare("
                INSERT INTO url_checks (url_id, status_code, created_at)
                VALUES (:url_id, :status_code, :created_at)
            ");
            $stmt->execute([
                ':url_id' => $urlId,
                ':status_code' => $statusCode,
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

// Запуск приложения
$app->run();
