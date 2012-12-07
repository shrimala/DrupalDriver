<?php

namespace Drupal\Driver;

use Drupal\Exception\BootstrapException,
    Drupal\Exception\UnsupportedDriverActionException,
    Drupal\DrupalExtension\Context\DrupalSubContextFinderInterface;

use Drupal\Driver\Cores\CoreInterface,
    Drupal\Driver\Cores\Drupal7 as Drupal7;

/**
 * Fully bootstraps Drupal and uses native API calls.
 */
class DrupalDriver implements DriverInterface, DrupalSubContextFinderInterface {
  private $drupalRoot;
  private $uri;
  private $bootstrapped = FALSE;
  public  $core;

  /**
   * Set Drupal root and URI.
   */
  public function __construct($drupalRoot, $uri) {
    $this->drupalRoot = realpath($drupalRoot);
    $this->uri = $uri;
    $version = $this->getDrupalVersion();
    // @todo figure out why entire namespace is required here.
    $class = '\Drupal\Driver\Cores\Drupal' . $version;
    $core = new $class($this->drupalRoot);
    $this->setCore($core);
  }

  /**
   * Implements DriverInterface::bootstrap().
   */
  public function bootstrap() {
    $this->getCore()->bootstrap();
  }

  /**
   * Implements DriverInterface::isBootstrapped().
   */
  public function isBootstrapped() {
    // Assume the blackbox is always bootstrapped.
    return $this->bootstrapped;
  }

  /**
   * Implements DriverInterface::userCreate().
   */
  public function userCreate(\stdClass $user) {
    // Default status to TRUE if not explicitly creating a blocked user.
    if (!isset($user->status)) {
      $user->status = 1;
    }

    // Clone user object, otherwise user_save() changes the password to the
    // hashed password.
    $account = clone $user;

    \user_save($account, (array) $user);

    // Store UID.
    $user->uid = $account->uid;
  }

  /**
   * Implements DriverInterface::userDelete().
   */
  public function userDelete(\stdClass $user) {
    \user_cancel(array(), $user->uid, 'user_cancel_delete');
  }

  /**
   * Implements DriverInterface::userAddRole().
   */
  public function userAddRole(\stdClass $user, $role_name) {
    $role = \user_role_load_by_name($role_name);

    if (!$role) {
      throw new \RuntimeException(sprintf('No role "%s" exists.', $role_name));
    }

    \user_multiple_role_edit(array($user->uid), 'add_role', $role->rid);
  }

  /**
   * Implements DriverInterface::fetchWatchdog().
   */
  public function fetchWatchdog($count = 10, $type = NULL, $severity = NULL) {
    // @todo
    throw new UnsupportedDriverActionException('No ability to access watchdog entries in %s', $this);
  }

  /**
   * Implements DriverInterface::clearCache().
   */
  public function clearCache($type = NULL) {
    // Need to change into the Drupal root directory or the registry explodes.
    $current_path = getcwd();
    chdir(DRUPAL_ROOT);
    \drupal_flush_all_caches();
    chdir($current_path);
  }

  /**
   * Implements DrupalSubContextFinderInterface::getPaths().
   */
  public function getSubContextPaths() {
    // Ensure system is bootstrapped.
    if (!$this->isBootstrapped()) {
      $this->bootstrap();
    }

    $paths = array();

    // Get enabled modules.
    $modules = \module_list();
    $paths = array();
    foreach ($modules as $module) {
      $paths[] = $this->drupalRoot . DIRECTORY_SEPARATOR . \drupal_get_path('module', $module);
    }

    // Themes.
    // @todo

    // Active profile
    // @todo

    return $paths;
  }

  /**
   * Determine major Drupal version.
   *
   * @throws BootstrapException
   *
   * @see drush_drupal_version()
   */
  function getDrupalVersion() {
    if (!isset($this->drupalVersion)) {
      // Support 6, 7 and 8.
      $version_constant_paths = array(
        // Drupal 6.
        '/modules/system/system.module',
        // Drupal 7.
        '/includes/bootstrap.inc',
        // Drupal 8.
        '/core/includes/bootstrap',
      );
      foreach ($version_constant_paths as $path) {
        if (file_exists($this->drupalRoot . $path)) {
          require_once $this->drupalRoot . $path;
        }
      }
      if (defined('VERSION')) {
        $version = VERSION;
      }
      else {
        throw new BootstrapException('Unable to determine Drupal core version. Supported versions are 6, 7, and 8.');
      }

      // Extract the major version from VERSION.
      $version_parts = explode('.', $version);
      if (is_numeric($version_parts[0])) {
        $this->drupalVersion = (integer) $version_parts[0];
      }
      else {
        throw new BootstrapException(sprintf('Unable to extract major Drupal core version from version string %s.', $version));
      }
    }
    return $this->drupalVersion;
  }

  /**
   * Instantiate and set Drupal core class.
   *
   * @param $version
   *   Drupal major version.
   */
  public function setCore(CoreInterface $core) {
    $this->core = $core;
  }

  /**
   * Return current core.
   */
  public function getCore() {
    return $this->core;
  }

  /**
   * Implements DriverInterface::createNode().
   */
  public function createNode(\stdClass $node) {
    // Default status to 1 if not set.
    if (!isset($node->status)) {
      $node->status = 1;
    }
    \node_save($node);
    return $node;
  }
}
