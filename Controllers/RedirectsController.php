<?php

namespace Statamic\Addons\SeoBox\Controllers;

use Illuminate\Http\Request;
use Statamic\API\Fieldset;
use Statamic\API\File;
use Statamic\API\YAML;
use Statamic\API\URL;
use Statamic\CP\Publish\ProcessesFields;
use Statamic\Events\Data\EntrySaved;
use Statamic\Events\Data\TermSaved;

use Statamic\Addons\SeoBox\Controllers\Controller;
use Statamic\Addons\SeoBox\Traits\Redirects\GeneratesDataUris;

class RedirectsController extends Controller
{

  use ProcessesFields;
  use GeneratesDataUris;

  const STORAGE_KEY = 'seo-redirects';

  const ROUTES_FILE = 'site/settings/routes.yaml';

  /**
   * Return the control panel redirects grid
   */
  public function index()
  {
    $fieldset = $this->createAddonFieldset('redirects');

    $data = $this->preProcessWithBlankFields(
        $fieldset,
        $this->extractGridDataFromFile()
    );

    return $this->view('cp', [
      'id' => null,
      'data' => $data,
      'title' => 'Redirection',
      'fieldset'=> $fieldset->toPublishArray(),
      'submitUrl' => route('seo-box.update-redirects')
    ]);
  }

  /**
   * Update the redirects data
   * @param Illuminate\Http\Request $request
   */
  public function cpUpdate(Request $request)
  {
    $this->updateSiteRoutesFromCP($request);
    return $this->updateStorage($request, self::STORAGE_KEY, 'seo-box.redirects'); // Is this necessary if we're reading only from the routes.yaml file?
  }

  /**
   * Write the routes generated in the CP to the routes.yaml file
   * @param Illuminate\Http\Request $request
   * @return null
   */
  public function updateSiteRoutesFromCP($request)
  {
    $existingRedirects = $this->readFromRoutesFile();
    $grid = $request->fields['redirects'];
    $routes = $this->collectRoutesFromData($grid);
    return $this->writeToRoutesFile(array_merge($existingRedirects, $routes));
  }

  /**
   * Convert the data returned from the CP grid into yaml
   * that can be written to `routes.yaml`
   * @param array $data Output from the grid
   * @return array
   */
  private function collectRoutesFromData($data)
  {
    $routes = ['redirect' => [], 'vanity' => []];
    foreach($data as $key => $redirect) {
      $type = $redirect['status_code'] === '301' ? 'redirect' : 'vanity';
      $routes[$type][$redirect['source']] = $redirect['target'];
    }
    return $routes;
  }

  /**
   * Convert the site `routes.yaml` file to an array that can be displayed in the CP
   * @return array
   */
  private function extractGridDataFromFile()
  {
    $redirects = $this->readFromRoutesFile();
    $data = [];

    $redirect = \array_key_exists('redirect', $redirects) ? $redirects['redirect'] : [];
    $vanity = \array_key_exists('vanity', $redirects) ?  $redirects['vanity'] : [];

    foreach($redirect as $from => $to) {
      $data[] = ['source' => $from, 'target' => $to, 'status_code' => '301'];
    }

    foreach($vanity as $from => $to) {
      $data[] = ['source' => $from, 'target' => $to, 'status_code' => '302'];
    }

    return ['redirects' => $data];
  }

  /**
   * Parse the site's `routes.yaml` file to an array
   * @return array
   */
  private static function readFromRoutesFile()
  {
    $redirectsFile = File::get(self::ROUTES_FILE);
    return YAML::parse($redirectsFile);
  }

  /**
   * Write an array of processed data to the site's `routes.yaml` file
   * @param array $data The data to be written
   * @return null
   */
  private static function writeToRoutesFile($data)
  {
    $yaml = YAML::dump($data);
    return File::put(self::ROUTES_FILE, $yaml);
  }


  /**
   * Will remove any redirects that will redirect infinitely
   * by taking out existing redirects in the $data that redirect
   * from the route you are redirecting $to
   * @param string $to
   * @param array $data
   * @return array
   */
  private static function removePotentialInfiniteRedirects($to, $data)
  {
    if(\array_key_exists($to, $data)) {
      unset($data[$to]);
    }
    return $data;
  }


  /**
   * Abstract URL transformation
   * @param string $path The path to transform
   * @return string The transformed url
   */
  private static function getRouteFromPath($path)
  {
    return URL::buildFromPath($path);
  }


  /**
   * Creates a new redirect
   * @param string $from The source route
   * @param string $to The target url
   * @param bool $isPermenant Should the redirect be 301?... or 302?
   */
  public static function create_redirect($from, $to, $isPermenant = true)
  {
    $category = $isPermenant ? 'redirect' : 'vanity';
    $existingRoutes = self::readFromRoutesFile();
    $existingRoutes[$category][$from] = $to;
    $existingRoutes[$category] = self::removePotentialInfiniteRedirects($to, $existingRoutes[$category]);
    return self::writeToRoutesFile($existingRoutes);
  }


  /**
   * Create a redirect when a page object is 'moved' in the sitetree
   * @param Statamic\Events\Data\PageMoved
   * @return null
   */
  public static function createRedirectFromPageMoved($event)
  {
    if($event->newPath === $event->oldPath) return;
    $oldPath = self::getRouteFromPath($event->oldPath);
    $newPath = self::getRouteFromPath($event->newPath);
    return self::create_redirect($oldPath, $newPath);
  }


  /**
   * Create a redirect when a page is saved (check for the slug change)
   * @param Statamic\Events\Data\PageSaved
   * @return null
   */
  public static function createRedirectFromPageSaved($event)
  {
    if($event->data->path() === $event->original['attributes']['path']) return;
    $oldPath = self::getRouteFromPath($event->original['attributes']['path']);
    $newPath = self::getRouteFromPath($event->data->path());
    return self::create_redirect($oldPath, $newPath);
  }


  /**
   * Extract the new/old routes from a data saved event
   * @param Statamic\Events\Data\ContentSaved $event
   * @return array
   */
  private static function extractChangedRoutesFromDataEvent($event)
  {
    $attrs = $event->original['attributes'];

    switch(true) {
      case ($event instanceof EntrySaved):
        $oldRoute = self::entry_uri($attrs['slug'], $attrs['collection']);
        break;
      case ($event instanceof TermSaved):
        $oldRoute = self::term_uri($attrs['slug'], $attrs['taxonomy']);
        break;
      default:
        $oldRoute = null;
    }

    return [
      'new' => $newRoute = $event->data->url(),
      'old' => $oldRoute
    ];
  }


  /**
   * Create a redirect when a collection entry is saved
   * @param Statamic\Events\Data\EntrySaved
   * @return null
   */
  public static function createRedirectFromDataSaved($event)
  {
    $attrs = $event->original['attributes'];
    if($event->data->slug() === $attrs['slug']) return;
    $routes = self::extractChangedRoutesFromDataEvent($event);
    return self::create_redirect($routes['old'], $routes['new']);
  }
}