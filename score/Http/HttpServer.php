<?php
namespace Swoolefy\Http;

use Swoole\Http\Server as http_server;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Swfy;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\Application;

// 如果直接通过php HttpServer.php启动时，必须include的vendor/autoload.php
if(isset($argv) && $argv[0] == basename(__FILE__)) {
	include_once '../../vendor/autoload.php';
}

class HttpServer extends BaseServer {
	/**
	 * $setting
	 * @var array
	 */
	public static $setting = [
		'reactor_num' => 1, //reactor thread num
		'worker_num' => 2,    //worker process num
		'max_request' => 100000,
		'task_worker_num' =>1,
		'task_tmpdir' => '/dev/shm',
		'daemonize' => 0,
		'log_file' => __DIR__.'/log.txt',
		'pid_file' => __DIR__.'/server.pid',
	];

	/**
	 * $App
	 * @var null
	 */
	public static $App = null;

	/**
	 * $webserver
	 * @var null
	 */
	public $webserver = null;

	/**
	 * $startctrl
	 * @var null
	 */
	public static $startCtrl = null;

	/**
	 * __construct
	 * @param array $config
	 */
	public function __construct(array $config=[]) {
		// 刷新字节缓存
		self::clearCache();

		self::$config = array_merge(
					include(__DIR__.'/config.php'),
					$config
			);
		self::$server = $this->webserver = new http_server(self::$config['host'], self::$config['port']);
		self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
		$this->webserver->set(self::$setting);
		parent::__construct();
		// 初始化启动类
		self::$startCtrl = isset(self::$config['start_init']) ? self::$config['start_init'] : 'Swoolefy\\Http\\StartInit';
	}

	public function start() {
		/**
		 * start回调
		 */
		$this->webserver->on('Start',function(http_server $server) {
			// 重新设置进程名称
			self::setMasterProcessName(self::$config['master_process_name']);
			// 启动的初始化函数
			self::$startCtrl::start($server);
		});
		/**
		 * managerstart回调
		 */
		$this->webserver->on('ManagerStart',function(http_server $server) {
			// 重新设置进程名称
			self::setManagerProcessName(self::$config['manager_process_name']);
			// 启动的初始化函数
			self::$startCtrl::managerStart($server);
		});

		/**
		 * 启动worker进程监听回调，设置定时器
		 */
		$this->webserver->on('WorkerStart',function(http_server $server, $worker_id) {
			// 记录主进程加载的公共files,worker重启不会在加载的
			self::getIncludeFiles();
			// 重启worker时，刷新字节cache
			self::clearCache();
			// 重新设置进程名称
			self::setWorkerProcessName(self::$config['worker_process_name'], $worker_id, self::$setting['worker_num']);
			// 设置worker工作的进程组
			self::setWorkerUserGroup(self::$config['www_user']);
			// 启动时提前加载文件
			self::startInclude();
			// 记录worker的进程worker_pid与worker_id的映射
			self::setWorkersPid($worker_id,$server->worker_pid);
			// 超全局变量server
       		Swfy::$server = $this->webserver;
       		Swfy::$config = self::$config;
       		// 初始化整个应用对象
			is_null(self::$App) && self::$App = swoole_pack(self::$config['application_index']::getInstance($config=[]));
       		// 启动的初始化函数
			self::$startCtrl::workerStart($server,$worker_id);
			
		});

		/**
		 * worker进程停止回调函数
		 */
		$this->webserver->on('WorkerStop',function(http_server $server, $worker_id) {
			// worker停止的触发函数
			self::$startCtrl::workerStop($server,$worker_id);
			
		});

		/**
		 * 接受http请求
		 */
		$this->webserver->on('request',function(Request $request, Response $response) {
			try{
				// google浏览器会自动发一次请求/favicon.ico,在这里过滤掉
				if($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
            		return $response->end();
       			}
				swoole_unpack(self::$App)->run($request, $response);
				return true;
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
		});

		/**
		 * 异步任务
		 */
		$this->webserver->on('task', function(http_server $server, $task_id, $from_worker_id, $data) {
			try {
				self::onTask($task_id, $from_worker_id, $data);
				return true;
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
			
		});

		/**
		 * 处理异步任务的结果
		 */
		$this->webserver->on('finish', function (http_server $server, $task_id, $data) {
			try {
				self::onFinish($task_id, $data);
				return true;
			}catch(\Exception $e) {
				// 捕捉异常
				\Swoolefy\Core\SwoolefyException::appException($e);
			}
			
		});

		/**
		 * worker进程异常错误回调函数
		 */
		$this->webserver->on('WorkerError',function(http_server $server, $worker_id, $worker_pid, $exit_code, $signal) {
			// worker停止的触发函数
			self::$startCtrl::workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
		});

		/**
		 * worker进程退出回调函数，1.9.17+版本
		 */
		if(static::compareSwooleVersion()) {
			$this->webserver->on('WorkerExit',function(http_server $server, $worker_id) {
				// worker退出的触发函数
				self::$startCtrl::workerExit($server, $worker_id);
			});
		}
		
		$this->webserver->start();
	}

	/**
	 * onTask 任务处理函数调度
	 * @param    object   $server
	 * @param    int         $task_id
	 * @param    int         $from_id
	 * @param    mixed    $data
	 * @return    void
	 */
	public function onTask($task_id, $from_id, $data) {
		list($class, $taskData) = $data;		
		// 实例任务
		if(is_string($class))  {
			
		}else if(is_array($class)) {
			// 类静态方法调用任务
			call_user_func_array($class, [$taskData]);
		}	
		return ;
	}

	/**
	 * onFinish 异步任务完成后调用
	 * @param    int          $task_id
	 * @param    mixed    $data
	 * @return void
	 */
	public function onFinish($task_id, $data) {
		list($callable, $taskData) = $data;
		if(!is_array($callable) || !is_array($taskData)) {
			return false;
		}
		call_user_func_array($callable, [$taskData]);
		return true;
	}

}

if(isset($argv) && $argv[0] == basename(__FILE__)) {
	$http = new HttpServer();
	$http->start();
}
