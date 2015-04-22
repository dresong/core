<?php
/**
 * @author Björn Schießle <schiessle@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Encryption\Keys;

use OC\Encryption\Util;
use OC\Files\Filesystem;
use OC\Files\View;
use OCP\Encryption\Exceptions\GenericEncryptionException;
use OCP\Encryption\Keys\IStorage;

class Storage implements IStorage {

	/** @var View */
	private $view;

	/** @var Util */
	private $util;

	// base dir where all the file related keys are stored
	private $keys_base_dir;
	private $encryption_base_dir;

	private $keyCache = array();

	/**
	 * @param string $encryptionModuleId
	 * @param View $view
	 * @param Util $util
	 */
	public function __construct(View $view, Util $util) {
		$this->view = $view;
		$this->util = $util;

		$this->encryption_base_dir = '/files_encryption';
		$this->keys_base_dir = $this->encryption_base_dir .'/keys';
	}

	/**
	 * @inheritdoc
	 */
	public function getUserKey($uid, $keyId, $encryptionModuleId) {
		$path = $this->constructUserKeyPath($encryptionModuleId, $keyId, $uid);
		return $this->getKey($path);
	}

	/**
	 * @inheritdoc
	 */
	public function getFileKey($path, $keyId, $encryptionModuleId) {
		$keyDir = $this->getFileKeyDir($encryptionModuleId, $path);
		return $this->getKey($keyDir . $keyId);
	}

	/**
	 * @inheritdoc
	 */
	public function getSystemUserKey($keyId, $encryptionModuleId) {
		$path = $this->constructUserKeyPath($encryptionModuleId, $keyId, null);
		return $this->getKey($path);
	}

	/**
	 * @inheritdoc
	 */
	public function setUserKey($uid, $keyId, $key, $encryptionModuleId) {
		$path = $this->constructUserKeyPath($encryptionModuleId, $keyId, $uid);
		return $this->setKey($path, $key);
	}

	/**
	 * @inheritdoc
	 */
	public function setFileKey($path, $keyId, $key, $encryptionModuleId) {
		$keyDir = $this->getFileKeyDir($encryptionModuleId, $path);
		return $this->setKey($keyDir . $keyId, $key);
	}

	/**
	 * @inheritdoc
	 */
	public function setSystemUserKey($keyId, $key, $encryptionModuleId) {
		$path = $this->constructUserKeyPath($encryptionModuleId, $keyId, null);
		return $this->setKey($path, $key);
	}

	/**
	 * @inheritdoc
	 */
	public function deleteUserKey($uid, $keyId, $encryptionModuleId) {
		$path = $this->constructUserKeyPath($encryptionModuleId, $keyId, $uid);
		return !$this->view->file_exists($path) || $this->view->unlink($path);
	}

	/**
	 * @inheritdoc
	 */
	public function deleteFileKey($path, $keyId, $encryptionModuleId) {
		$keyDir = $this->getFileKeyDir($encryptionModuleId, $path);
		return !$this->view->file_exists($keyDir . $keyId) || $this->view->unlink($keyDir . $keyId);
	}

	/**
	 * @inheritdoc
	 */
	public function deleteAllFileKeys($path, $encryptionModuleId) {
		$keyDir = $this->getFileKeyDir($encryptionModuleId, $path);
		$path = dirname($keyDir);
		return !$this->view->file_exists($path) || $this->view->deleteAll($path);
	}

	/**
	 * @inheritdoc
	 */
	public function deleteSystemUserKey($keyId, $encryptionModuleId) {
		$path = $this->constructUserKeyPath($encryptionModuleId, $keyId, null);
		return !$this->view->file_exists($path) || $this->view->unlink($path);
	}

	/**
	 * construct path to users key
	 *
	 * @param string $keyId
	 * @param string $uid
	 * @return string
	 */
	protected function constructUserKeyPath($encryptionModuleId, $keyId, $uid) {

		if ($uid === null) {
			$path = $this->encryption_base_dir . '/' . $encryptionModuleId . '/' . $keyId;
		} else {
			$path = '/' . $uid . $this->encryption_base_dir . '/'
				. $encryptionModuleId . '/' . $uid . '.' . $keyId;
		}

		return $path;
	}

	/**
	 * read key from hard disk
	 *
	 * @param string $path to key
	 * @return string
	 */
	private function getKey($path) {

		$key = '';

		if ($this->view->file_exists($path)) {
			if (isset($this->keyCache[$path])) {
				$key =  $this->keyCache[$path];
			} else {
				$key = $this->view->file_get_contents($path);
				$this->keyCache[$path] = $key;
			}
		}

		return $key;
	}

	/**
	 * write key to disk
	 *
	 *
	 * @param string $path path to key directory
	 * @param string $key key
	 * @return bool
	 */
	private function setKey($path, $key) {
		$this->keySetPreparation(dirname($path));

		$result = $this->view->file_put_contents($path, $key);

		if (is_int($result) && $result > 0) {
			$this->keyCache[$path] = $key;
			return true;
		}

		return false;
	}

	/**
	 * get path to key folder for a given file
	 *
	 * @param string $path path to the file, relative to data/
	 * @return string
	 * @throws GenericEncryptionException
	 * @internal param string $keyId
	 */
	private function getFileKeyDir($encryptionModuleId, $path) {

		if ($this->view->is_dir($path)) {
			throw new GenericEncryptionException("file was expected but directory was given: $path");
		}

		list($owner, $filename) = $this->util->getUidAndFilename($path);
		$filename = $this->util->stripPartialFileExtension($filename);

		// in case of system wide mount points the keys are stored directly in the data directory
		if ($this->util->isSystemWideMountPoint($filename, $owner)) {
			$keyPath = $this->keys_base_dir . $filename . '/';
		} else {
			$keyPath = '/' . $owner . $this->keys_base_dir . $filename . '/';
		}

		return Filesystem::normalizePath($keyPath . $encryptionModuleId . '/', false);
	}

	/**
	 * move keys if a file was renamed
	 *
	 * @param string $source
	 * @param string $target
	 * @param string $owner
	 * @param bool $systemWide
	 */
	public function renameKeys($source, $target) {

		list($owner, $source) = $this->util->getUidAndFilename($source);
		list(, $target) = $this->util->getUidAndFilename($target);
		$systemWide = $this->util->isSystemWideMountPoint($target, $owner);

		if ($systemWide) {
			$sourcePath = $this->keys_base_dir . $source . '/';
			$targetPath = $this->keys_base_dir . $target . '/';
		} else {
			$sourcePath = '/' . $owner . $this->keys_base_dir . $source . '/';
			$targetPath = '/' . $owner . $this->keys_base_dir . $target . '/';
		}

		if ($this->view->file_exists($sourcePath)) {
			$this->keySetPreparation(dirname($targetPath));
			$this->view->rename($sourcePath, $targetPath);
		}
	}

	/**
	 * copy keys if a file was renamed
	 *
	 * @param string $source
	 * @param string $target
	 * @param string $owner
	 * @param bool $systemWide
	 */
	public function copyKeys($source, $target) {

		list($owner, $source) = $this->util->getUidAndFilename($source);
		list(, $target) = $this->util->getUidAndFilename($target);
		$systemWide = $this->util->isSystemWideMountPoint($target, $owner);

		if ($systemWide) {
			$sourcePath = $this->keys_base_dir . $source . '/';
			$targetPath = $this->keys_base_dir . $target . '/';
		} else {
			$sourcePath = '/' . $owner . $this->keys_base_dir . $source . '/';
			$targetPath = '/' . $owner . $this->keys_base_dir . $target . '/';
		}

		if ($this->view->file_exists($sourcePath)) {
			$this->keySetPreparation(dirname($targetPath));
			$this->view->copy($sourcePath, $targetPath);
		}
	}

	/**
	 * Make preparations to filesystem for saving a keyfile
	 *
	 * @param string $path relative to the views root
	 */
	protected function keySetPreparation($path) {
		// If the file resides within a subdirectory, create it
		if (!$this->view->file_exists($path)) {
			$sub_dirs = explode('/', ltrim($path, '/'));
			$dir = '';
			foreach ($sub_dirs as $sub_dir) {
				$dir .= '/' . $sub_dir;
				if (!$this->view->is_dir($dir)) {
					$this->view->mkdir($dir);
				}
			}
		}
	}

}
