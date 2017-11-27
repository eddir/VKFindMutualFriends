<?php

const LIMIT_DEEP = 4;
const LIMIT_TIME = 60;
const LIMIT_REQUESTS = 4000;
const LIMIT_REQUESTS_PER_SECOND = 80;

const SERVICE_KEY = 'YOUR_ACCESS_TOKEN_HERE';
const LOG_FILE = __DIR__.'/vfflog.txt';
const DEBUG = false;

file_put_contents(LOG_FILE, '');
set_time_limit(LIMIT_TIME);

function shutdown() {
	FindMutualFriends::close('Превышено допустимое время выполнения');
}
register_shutdown_function('shutdown');

if (isset($_GET['sid']) && isset($_GET['tid'])) {
	$source_id = trim(array_pop(explode('vk.com/', $_GET['sid'])));
	$target_id = trim(array_pop(explode('vk.com/', $_GET['tid']))); 
	define('NL', '<br>');
} elseif (isset($argv[2])) {
	$source_id = trim(array_pop(explode('vk.com/', $argv[1])));
	$target_id = trim(array_pop(explode('vk.com/', $argv[2])));
	define('NL', PHP_EOL);
} else {
	define('NL', '');
	FindMutualFriends::close('Не указаны id пользователей');
}

FindMutualFriends::infoLog('Результат будет готов приблизительно через '.LIMIT_REQUESTS/LIMIT_REQUESTS_PER_SECOND.' сек.');
FindMutualFriends::infoLog('Проверяю параметры');

if (strlen($source_id) < 1)
	FindMutualFriends::close('Недопустимый id первого пользователя');
if (strlen($target_id) < 1)
	FindMutualFriends::close('Недопустимый id второго пользователя');

$response = FindMutualFriends::vkapi('users.get', ['user_ids' => $source_id]);
if (isset($response['response'][0]['uid'])) {
	$source_id = $response['response'][0]['uid'];
} else {
	FindMutualFriends::close('Первый пользователь не существует или набран неправильно');
}

$response = FindMutualFriends::vkapi('users.get', ['user_ids' => $target_id]);
if (isset($response['response'][0]['uid'])) {
	$target_id = $response['response'][0]['uid'];
} else {
	FindMutualFriends::close('Второй пользователь не существует или набран неправильно');
}
sleep(1);

$source_friends = FindMutualFriends::vkapi('friends.get', ['user_id' => $source_id, 'count' => 1]);
if (count($source_friends['response']) == 0) {
	FindMutualFriends::close('У первого пользователя нет друзей');
}
$target_friends = FindMutualFriends::vkapi('friends.get', ['user_id' => $source_id, 'count' => 1]);
if (count($target_friends['response']) == 0) {
	FindMutualFriends::close('У второго пользователя нет друзей');
}
sleep(1);

FindMutualFriends::infoLog('Запускаю поиск');

$fmf = new FindMutualFriends($source_id, $target_id);
$fmf->run();

class FindMutualFriends {
	
	private $step = 0;
	private $requests = 0;
	private $requests_total = 0;
	private $source;
	private $target;
	
	public function __construct($source, $target) {
		$this->source = $source;
		$this->target = $target;
	}
	
	public function run() {
		$this->search_mutual($this->target);
	}
	
	private function search_mutual($target, $step = 0) {
		$path = array($target);
		$friends[1][$target] = array($this->getFriends($target), $path);
		if (empty($friends[1][$target][0]))
			$this->close('У второго пользователя нет друзей');
		
		while ($step <= LIMIT_DEEP) {
			++$step;
			$this->debugLog('Глубина: '.$step);
			$this->debugLog('Блоков: '.count($friends[$step]));
			foreach ($friends[$step] as $parrent => $chunk) {
				foreach ($chunk[0] as $friend) {
					if ($friend == $this->source) {
						$chunk[1][] = $friend;
						$this->show_users($chunk[1]);
					}
				}
			}
			if ($step <= LIMIT_DEEP) {
				foreach ($friends[$step] as $parrent => $chunk) {
					foreach ($chunk[0] as $friend) {
						$path = $chunk[1];
						$path[] = $friend;
						$friends[$step + 1][$friend] = array($this->getFriends($friend), $path);
					}
				}
			} else {
				$this->close('Ничего не найдено');
			}
		}
	}
	
	private function getFriends($user) {
		$response = $this->get('friends.get', ['user_id' => $user]);
		if (isset($response['error']))
			return array();
			
		$friends = $response['response'];
		if(count($response['response']) == 5000) {
			$response = $this->get('friends.get', ['user_id' => $user, 'offset' => 5000]);
			$friends = array_merge($friends, $response['response']);
		}
		return $friends;
	}
	
	public function show_users($users) {
		$list = '';
		foreach ($users as $u) {
			$list .= $u.',';
		}
		$info = $this->get('users.get', ['user_ids' => $list]);
		if (isset($info['response'])) {
			$target = array_shift($info['response']);
			$output = 'Цепочка: ' . $target['first_name'] . ' ' . $target['last_name'];
			foreach ($info['response'] as $u) {
				$output .= ' -> ' . $u['first_name'] . ' ' . $u['last_name'];
			}
			$this->infoLog('Найдено совпадение!');
			$this->infoLog($output);
			$this->close('Конец алгоритма.');
		}
	}
	
	public function get($method, $args) {
		if (++$this->requests_total >= LIMIT_REQUESTS) {
			$this->close('Превышено максимальное допустимое число запросов');
		}
		if (++$this->requests >= LIMIT_REQUESTS_PER_SECOND) {
			$this->requests = 0;
			$this->debugLog(LIMIT_REQUESTS_PER_SECOND . ' запроса. Ждём секунду.');
			$this->infoLog('До конца осталось '.(LIMIT_REQUESTS - $this->requests_total)/LIMIT_REQUESTS_PER_SECOND.' сек.');
			sleep(1);
		}
		$this->debugLog('Запрос '.$method);
		return $this->vkapi($method, $args);
	}

	public static function vkapi($method, $args, $dieOnError = true) {
		$url = 'https://api.vk.com/method/'.$method.'?access_token='.SERVICE_KEY.'&';
		foreach ($args as $index => $value) {
			$value = str_replace([" ", "&", "#", ";", "%n%"], ["%20", "%26", "%23", "%3B", "%0A"], $value);
			$url .= $index."=".$value."&";
		}
		$response = Self::curl($url);
		if (!isset($response['response']) && !isset($response['error'])) {
			if (DEBUG) {
				var_dump($response);
				var_dump($url);
			}
			if ($dieOnError)
				Self::close('Произошла неизвестная ошибка при отправке запроса.');
			return false;
		} elseif (isset($response['error']) && !in_array($response['error']['error_code'], array(15, 18))) {
			Self::close('Произошла ошибка при работе с API: '.$response['error']['error_msg']);
		}
		return $response;
	}

	public static function curl($url) {
		$curlObject = curl_init($url);
		curl_setopt($curlObject, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curlObject, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($curlObject, CURLOPT_RETURNTRANSFER, true);
		$data = @curl_exec($curlObject);
		@curl_close($curlObject);
		if ($data) {
			return json_decode($data, true);
		}
		return false;
	}
	
	public static function debugLog($msg) {
		if (DEBUG) {
			Self::log(' [DEBUG] '. $msg);
		}
	}
	
	public static function infoLog($msg) {
		Self::log(' [INFO] '. $msg);
	}
	
	public static function close($msg) {
		Self::log(' [CLOSE] '. $msg);
		exit();
	}
	
	public static function log($text) {
		$msg = date('Y-m-d h:i:s') . $text . NL;
		$fp = fopen(LOG_FILE, 'a');
		fwrite($fp, $msg);
		fclose($fp);
		echo $msg;
	}

}

?>
