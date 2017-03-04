<?php
/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */

require '/www/notifier/vendor/autoload.php';

use Joli\JoliNotif\Notification;
use Joli\JoliNotif\NotifierFactory;

class RoboFile extends \Robo\Tasks
{
	const SERVER = "/www/";
	const FORUM = "/www/public_html/devboards/";
	const SVN = "/www/SVN/";

	protected $dirsToScan = array();
	protected $productId = '';
	protected $vB3 = NULL;
	protected $vB4 = NULL;
	protected $vB5 = NULL;

	public function symlinkCreate()
	{
		$this->scanForConfigs();
		$this->createSymlinks();
		$this->notify('Symlink Updater', 'Symlinks created successfully.');
	}

	public function symlinkClean($opts = ['erase' => false])
	{
		$this->cleanSymlinks($opts);
		$this->notify('Symlink Updater', 'Symlinks ' . ($opts['erase'] ? 'erased' : 'cleaned') . ' successfully.');
	}

	public function syntaxCheck($onlyDir = '')
	{
		$this->scanForConfigs($onlyDir);
		$this->checkSyntax();
	}

	public function release($productId = '')
	{
		$this->openDatabaseConnections();
		//$this->validateProductId($productId);
		//$this->loadProVersion();
		//$this->loadLiteVersion();
		//$this->commitSVN();
		//$this->tagSVN();


		/*
		if (!$productId)
		{
			// Init this
			$products = array();
			$i = 1;

			$res = $vB3->query("
				SELECT product.productid, product.title
				FROM vb_dbtech_devtools_product AS devtools
				LEFT JOIN vb_product AS product ON(product.productid = devtools.product)
				WHERE devtools.exclude = '0'
			");
			while ($product = $res->fetch(PDO::FETCH_ASSOC))
			{
				// Index by ID
				$products[$product['productid']] = $product['title'];
			}

			$res = $vB4->query("
				SELECT product.productid, product.title
				FROM vb_dbtech_devtools_product AS devtools
				LEFT JOIN vb_product AS product ON(product.productid = devtools.product)
				WHERE devtools.exclude = '0'
			");
			while ($product = $res->fetch(PDO::FETCH_ASSOC))
			{
				// Index by id
				$products[$product['productid']] = $product['title'];
			}

			// Sort by title
			asort($products);

			// Now grab our keys
			$productIds = array_keys($products);

			foreach ($productIds as $i => $product_id)
			{
				// Output the product list
				$this->say("$i) $products[$product_id]");
			}

			// Request the product from the user
			$requestedProduct = $this->ask('Enter the key of the product you want to release:');

			if (!isset($products[$requestedProduct]))
			{
				// Stop
				die();
			}

			// Shorthand
			$productId = $products[$requestedProduct];
		}
		*/
	}

	protected function scanForConfigs($onlyDir = '')
	{
		if ($onlyDir)
		{
			if (file_exists(self::SVN . $onlyDir . '/config.inc.php'))
			{
				// Grab the configuration file
				require(self::SVN . $onlyDir . '/config.inc.php');
			}
		}
		else
		{
			$d = dir(self::SVN);
			while (false !== ($entry = $d->read()))
			{
				if (
					$entry{0} == '.'
					OR !is_dir(self::SVN . $entry)
					OR !file_exists(self::SVN . $entry . '/config.inc.php')
				)
				{
					// Skip this
					//$this->say("Skipping $entry...");

					// Skip this
					continue;
				}

				// Grab the configuration file
				require(self::SVN . $entry . '/config.inc.php');
			}
			$d->close();
		}
	}

	protected function updateFramework($pathToApplication, $version, $replace = [])
	{
		$this->_mirrorDir(self::SVN . 'framework/framework/tags/' . $version, $pathToApplication);

		$d = new RecDir($pathToApplication . '/');
		while (false !== ($file = $d->read()))
		{
			$this->taskReplaceInFile($file)
				->from(array_keys($replace))
				->to($replace)
			->run();
		}
		$d->close();
	}

	protected function createSymlinks()
	{
		foreach ($this->dirsToScan as $folder => $dirs)
		{
			foreach ($dirs as $dir)
			{
				$d = new RecDir($dir);
				while (false !== ($entry = $d->read()))
				{
					// Grab our base entry
					$baseEntry = str_replace($dir, '', $entry);

					if (!is_dir(dirname(self::FORUM . $folder . '/' . $baseEntry)))
					{
						// Destination directory doesn't exist
						mkdir(dirname(self::FORUM . $folder . '/' . $baseEntry), 0755, true);
					}

					if (!is_link(self::FORUM . $folder . '/' . $baseEntry))
					{
						if (file_exists(self::FORUM . $folder . '/' . $baseEntry))
						{
							// Get rid of the "normal" file
							@unlink(self::FORUM . $folder . '/' . $baseEntry);
						}

						// Create the symlink
						$this->taskFileSystemStack()
							->symlink($entry, self::FORUM . $folder . '/' . $baseEntry)
						->run();
					}
				}
				$d->close();
			}
		}
	}

	protected function cleanSymlinks($opts)
	{
		$d = new RecDir(self::FORUM);
		while (false !== ($entry = $d->read()))
		{
			if (is_link($entry) AND (!@readlink($entry) OR $opts['erase']))
			{
				// Remove the symlink
				@unlink($entry);

				// Log this
				$this->say('Deleted Symlink: ' . $entry);
			}
		}
		$d->close();
	}

	protected function checkSyntax()
	{
		$taskStack = $this->taskExecStack()->printed(false)->stopOnFail();
		foreach ($this->dirsToScan as $folder => $dirs)
		{
			foreach ($dirs as $dir)
			{
				$d = new RecDir($dir);
				while (false !== ($entry = $d->read()))
				{
					if (pathinfo($entry, PATHINFO_EXTENSION) == 'php')
					{
						$taskStack = $taskStack->exec('php -l ' . $entry);
					}
				}
				$d->close();
			}
		}
		$taskStack->run();
	}

	protected function openDatabaseConnections()
	{
		// Open a couple DB connections
		$this->vB3 = new PDO('mysql:dbname=vb3;host=localhost', 'root', 'revanza');
		$this->vB4 = new PDO('mysql:dbname=vb4;host=localhost', 'root', 'revanza');
		$this->vB5 = new PDO('mysql:dbname=vb5;host=localhost', 'root', 'revanza');
	}

	protected function addDirToScan($dir, $folder)
	{
		if (!isset($this->dirsToScan[$folder]))
		{
			// Make sure this is set
			$this->dirsToScan[$folder] = array();
		}

		// Now store the dir
		$this->dirsToScan[$folder][] = $dir;
	}

	protected function notify($title, $body)
	{
		/** @var \Joli\JoliNotif\Notifier $notifier */
		$notifier = NotifierFactory::create();

		if ($notifier)
		{
			$notification =
				(new Notification())
				->setTitle($title)
				->setBody($body);
			;

			$notifier->send($notification);
		}
	}
}


class RecDir
{
	protected $currentPath;
	protected $slash;
	protected $rootPath;
	protected $recursiveTree;
	protected $excludedRootDirs;

	function __construct($rootPath, $win = false, $excludedRootDirs = array())
	{
		switch ($win)
		{
			case true:
				$this->slash = '\\';
				break;
			default:
				$this->slash = '/';
		}
		$this->rootPath = $rootPath;
		$this->currentPath = $rootPath;
		$this->excludedRootDirs = $excludedRootDirs;
		$this->recursiveTree = array(dir($this->rootPath));
		$this->rewind();
	}

	function __destruct()
	{
		$this->close();
	}

	public function close()
	{
		while (true === ($d = array_pop($this->recursiveTree)))
		{
			$d->close();
		}
	}

	public function closeChildren()
	{
		while (count($this->recursiveTree) > 1 AND false !== ($d = array_pop($this->recursiveTree)))
		{
			$d->close();
			return true;
		}
		return false;
	}

	public function getRootPath()
	{
		if (isset($this->rootPath))
		{
			return $this->rootPath;
		}
		return false;
	}

	public function getCurrentPath()
	{
		if (isset($this->currentPath))
		{
			return $this->currentPath;
		}
		return false;
	}

	public function read($debug = false)
	{
		if ($debug)
		{
			var_dump($this->recursiveTree);
		}
		while (count($this->recursiveTree) > 0)
		{
			$d = end($this->recursiveTree);
			if ((false !== ($entry = $d->read())))
			{
				if ($entry != '.' AND $entry != '..' AND $entry != '.DS_Store')
				{
					$path = $d->path . $entry;

					if (is_file($path))
					{
						return $path;
					}
					else if (is_dir($path . $this->slash))
					{
						$this->currentPath = $path . $this->slash;
						if ($child = @dir($path . $this->slash))
						{
							$this->recursiveTree[] = $child;
						}
					}
				}
			}
			else
			{
				array_pop($this->recursiveTree)->close();
			}
		}
		return false;
	}

	public function rewind()
	{
		$this->closeChildren();
		$this->rewindCurrent();
	}

	public function rewindCurrent()
	{
		return end($this->recursiveTree)->rewind();
	}
}