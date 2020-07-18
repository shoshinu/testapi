<?php

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(true, true, true);


// дополняем нулями значения спереди и сзади
// $num - количество нулей, которые нужно добавить
// point = false - с начала, true - с конца
$addZeroReturn = function ($value, $num, $point = false) {
    $tmpValue = '';

    for ($i = 0; $i < $num; $i++) {
        $tmpValue .= '0';
    }

    return $point ? $value . $tmpValue : $tmpValue . $value;
};


// формируем вывод айди / валюта / курс
$createValutesList = function ($result) use ($addZeroReturn) {
    $body = "<pre>id      курс            валюта\n";
    foreach ($result as $val) {
        $rateNew = null;
        $rate = explode(".", $val['rate']);

        if (strlen($rate[1]) < 4) {
            $addZeroReturn($rate[1], (4 - strlen($rate[1])), 1);
        }

        $rate[1] = $rateNew ? $rateNew : $rate[1];
        $rate = implode (',', $rate);

        $body .= ($val['id'] > 100
            ? $val['id']
            : $addZeroReturn($val['id'], 1))
            . "\t{$rate}\t\t{$val['name']}\n";
        $rate = null;
    }

    return $body . '</pre>';
};


// пользователь может проверить авторизован или нет
$app->get('/auth/{token}', function (Request $request, Response $response, $args) {
    if (!empty($args['token']) && is_file('/var/www/first/auth/' . $args['token'])) {
        $response->getBody()->write("Вы авторизованы\n");
    } else {
        $response->getBody()->write("Вы не авторизованы!\n");
    }

    return $response;
});


// создание авторизационного токена
$app->post('/auth/{t}', function (Request $request, Response $response, $args) {
    session_start();

    if ($_POST['test'] && $_POST['test'] == $args['t']) {
        $auth = sha1(session_id() . $_POST['test']);
        echo "\nСоздан токен авторизации: /var/www/first/public/auth/{$auth}\n";
        file_put_contents(
            '/var/www/first/auth/' . $auth,
            $_POST['test']
            . "\n" . session_id()
            . "\n" . $_SERVER['REMOTE_ADDR']
            . "\n" . time()
            . "\n" . md5($_SERVER['HTTP_USER_AGENT']),
            1
        );
        echo "\nСохраните токен: {$auth} \nДля проверки доступа пройдите по ссылке: /auth/<token>\n";
        $_SESSION['testp3'] = $auth;
    } else {
        $response->getBody()->write("Вы не авторизованы!\n");
    }

    return $response;
});


// добавление и обновление данных таблицы currency из ресурса
$app->get('/update/{token}', function (Request $request, Response $response, $args) {

    if (!empty($args['token']) && is_file('/var/www/first/auth/' . $args['token'])) {
        require_once __DIR__ . "/../app/db_connect.php";

        // нужный url откуда тянем информацию:
        $url = 'http://www.cbr.ru/scripts/XML_daily.asp';
        $data = file_get_contents($url);

        $parser = xml_parser_create();
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
        xml_parse_into_struct($parser, $data, $values, $tags);
        xml_parser_free($parser);

        $tempData = [];
        foreach ($tags as $key => $val) {
            if ($key == 'Valute') {
                $ranges = $val;
                // каждая смежная пара значений массивов является верхней и
                // нижней границей определения молекулы
                for ($i = 0; $i < count($ranges); $i += 2) {
                    $offset = $ranges[$i] + 1;
                    $len = $ranges[$i + 1] - $offset;
                    $tempData[] = array_slice($values, $offset, $len);
                }
            }
        }

        $allTableDate = $row = $sqlRowsData = [];
        $names = [
            'NumCode',
            'Name',
            'Value'
        ];

        // формирование массива валют
        foreach($tempData as $key => $val) {
            for ($i = 0; $i < count($val); $i++) {
                if (in_array($val[$i]['tag'], $names)) {
                    $row[] = $val[$i]['value'];
                }
            }

            $allTableDate[] = $row;
            $row = [];
        }

        // создание запроса на добавление
        // запрос на обновление
        $log_upd = $sql_upd = $sqlRowsData = [];
        $sql = "INSERT INTO currency (id, name, rate) VALUES \n";

        foreach ($allTableDate as $key => $val) {
            $sqlRowsData[] =
                "("
                . (int)$val[0]
                . ", '{$val[1]}'"
                . ", " . str_replace(',', '.', $val[2])
                . ")";
        }

        $sql .= implode(",\n", $sqlRowsData);

        // первое добавление
        $res_ins = $mysql_connect->query($sql);

        if ($res_ins) {
            $response->getBody()->write("Значения добавлены!\n");
        } else {
            $res_upd = false;
            $sql_prepare = $mysql_connect->prepare('UPDATE currency SET name = ?, rate = ? WHERE id = ?');

            foreach ($allTableDate as $key => $val) {
                $res_upd = $sql_prepare->execute([
                    "{$val[1]}",
                    (float) str_replace(',', '.', $val[2]),
                    (int) $val[0]
                ]);

                $log_upd[] = (int) $val[0];
            }

            if ($res_upd) {
                $response->getBody()->write("Курсы валют обновлены\n");
            } else {
                $response->getBody()->write("При обновлении возникла ошибка, проверьте входные данные!\n");
            }
        }
    } else {
        $response->getBody()->write("Вы не авторизованы!\n");
    }

    return $response;
});


// выборка ззначения валюты по конкретному айди
$app->get('/currency/{id}/{token}', function (Request $request, Response $response, $args) use ($addZeroReturn) {

    if (!empty($args['token']) && is_file('/var/www/first/auth/' . $args['token'])) {
        require_once __DIR__ . "/../app/db_connect.php";

        $sql_res = $mysql_connect->query('SELECT rate FROM currency WHERE id = ' . (int) $args['id']);
        $result = $sql_res->fetch();
        $result = $result['rate'] ? $result['rate'] : 'Нет данных';
        $decimal = explode('.', $result);
        $decimal[1] = $addZeroReturn($decimal[1], (4 - strlen($decimal[1])), 1);
        $result = implode('.', $decimal);
        $response->getBody()->write("{$result} \n");
    } else {
        $response->getBody()->write("Вы не авторизованы!\n");
    }

    return $response;
});


// список курсов валют
$app->get('/currencies/{page}/{quantity}/{token}',
            function (Request $request, Response $response, $args) use ($createValutesList) {

    if (!empty($args['token']) && is_file('/var/www/first/auth/' . $args['token'])) {
        require_once __DIR__ . "/../app/db_connect.php";

        $body = 'Неверные входные параметры, попробуйте еще раз';

        // выборка количества записей
        $sql_res = $mysqlConnect->query('SELECT COUNT(*) as count FROM currency');
        $total = $sql_res->fetch();
        $result = [];

        if ($total['count'] > 0 && (empty($args['quantity']) || $args['quantity'] == 'all')) {
            // выборка всех значений записей
            $sql_res = $mysqlConnect->query('SELECT * FROM currency');
        } elseif (!empty($args['quantity']) && (int) $args['quantity'] <= $total['count']) {
            // выборка по пагинации page - номер страницы, quantity - количество на странице
            $limit = $args['quantity'];
            $offset = $limit * ($args['page'] - 1);
            $sql_res = $mysqlConnect->query("SELECT * FROM currency LIMIT {$limit} OFFSET {$offset}");
        }

        $result = $sql_res->fetchAll();
        $body = $createValutesList($result);
        $response->getBody()->write($body);
    } else {
        $response->getBody()->write("Вы не авторизованы!\n");
    }

    return $response;
});


// help
$app->get('/commands/{token}', function (Request $request, Response $response, $args) {

    if (!empty($args['token']) && is_file('/var/www/first/auth/' . $args['token'])) {
    $body = "<pre>";
    $body .= "Команды доступны по протоколу GET с добавлением токена авторизации.\n\n";
    $body .= "Доступные команды GET:\n\n";
    $body .= "/auth/{token}\n";
    $body .= "\t- запрос проверки авторизации\n\n";
    $body .= "/update/{token}\n";
    $body .= "\t- сохранение и обновление данных курсов валют в БД\n\n";
    $body .= "/currency/{id}/{token}\n";
    $body .= "\t- {id} код валюты - запрос показывает значение курса\n\n";
    $body .= "/currencies/{page}/{quantity}/{token}\n";
    $body .= "\t- {page} - номер страницы, {quantity} количество на странице (all - показать всё)\n\n";
    $body .= "/commands/{page}/{quantity}/{token}\n";
    $body .= "\t- справка по командам\n\n";
    $body .= "\n";
    $body .= "Доступные команды POST:\n";
    $body .= "/auth/{t}\n";
    $body .= "\t- {t} - чисто, для создания токена авторизации {t} в конце строки запросо должно быть равным значению
                передаваемым в POST['test'] - пост-запроса 'test={t}, пример для cURL:\n
              \tcurl -d 'test=15' http://server.address/auth/15\n";
    $body .= "\n";
    $body .= '</pre>';

    $response->getBody()->write($body);
    } else {
        $response->getBody()->write("Вы не авторизованы!\n");
    }

    return $response;
});


$app->run();
