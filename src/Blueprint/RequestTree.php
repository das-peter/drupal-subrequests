<?php


namespace Drupal\subrequests\Blueprint;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contains the hierarchical information of the requests.
 */
class RequestTree {

  const SUBREQUEST_TREE = '_subrequests_tree_object';
  const SUBREQUEST_ID = '_subrequests_content_id';
  const SUBREQUEST_DONE = '_subrequests_is_done';

  /**
   * @var \Symfony\Component\HttpFoundation\Request[]
   */
  protected $requests;

  /**
   * If this tree sprouts from another requests, save the request id here.
   * @var string
   */
  protected $parentId;

  /**
   * RequestTree constructor.
   *
   * @param \Symfony\Component\HttpFoundation\Request[] $requests
   * @param string $parent_id
   */
  public function __construct(array $requests, $parent_id = NULL) {
    $this->requests = $requests;
    $this->parentId = $parent_id;
  }

  /**
   * Gets a flat list of the initialized requests for the current level.
   *
   * All requests returned by this method can run in parallel. If a request has
   * children requests depending on it (sequential) the parent request will
   * contain a RequestTree itself.
   *
   * @return \Symfony\Component\HttpFoundation\Request[]
   *   The list of requests.
   */
  public function getRequests() {
    return $this->requests;
  }

  /**
   * Is this tree the base one?
   *
   * @return bool
   *   TRUE if the tree is for the master request.
   */
  public function isRoot() {
    return !$this->getParentId();
  }

  /**
   * Get the parent ID of the request this tree belongs to.
   *
   * @return string
   */
  public function getParentId() {
    return $this->parentId;
  }

  /**
   * Find all the sub-trees in this tree.
   *
   * @return static[]
   *   An array of trees.
   */
  public function getSubTrees() {
    $trees = array_map(function (Request $request) {
      return $request->attributes->get(static::SUBREQUEST_TREE);
    }, $this->getRequests());
    return array_filter($trees);
  }

  /**
   * Find a request in a tree based on the request ID.
   *
   * @param string $request_id
   *   The unique ID of a request in the blueprint to find in this tree.
   *
   * @return \Symfony\Component\HttpFoundation\Request|NULL $request
   *   The request if found. NULL if not found.
   */
  public function getDescendant($request_id) {
    // Search this level's requests.
    $found = array_filter($this->getRequests(), function (Request $request) use ($request_id) {
      return $request->attributes->get(static::SUBREQUEST_ID) == $request_id;
    });
    if (count($found)) {
      return reset($found);
    }
    // If the request is not in this level, then traverse the children's trees.
    $found = array_filter($this->getRequests(), function (Request $request) use ($request_id) {
      /** @var static $sub_tree */
      if (!$sub_tree = $request->attributes->get(static::SUBREQUEST_TREE)) {
        return FALSE;
      }
      return $sub_tree->getDescendant($request_id);
    });
    if (count($found)) {
      return reset($found);
    }
    return NULL;
  }

  /**
   * Is the request tree done?
   *
   * @return bool
   *   TRUE if all the requests in the tree and it's descendants are done.
   */
  public function isDone() {
    // The tree is done if all of the requests and their children are done.
    return array_reduce($this->getRequests(), function ($is_done, Request $request) {
      return $is_done && static::isRequestDone($request);
    }, TRUE);
  }

  /**
   * Check if a request and all its possible children are done.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return bool
   *   TRUE if is done. FALSE otherwise.
   */
  protected static function isRequestDone(Request $request) {
    // If one request is not done, the whole tree is not done.
    if (!$request->attributes->get(static::SUBREQUEST_DONE)) {
      return FALSE;
    }
    // If the request has children, then make sure those are done too.
    /** @var static $sub_tree */
    if ($sub_tree = $request->attributes->get(static::SUBREQUEST_TREE)) {
      if (!$sub_tree->isDone()) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
