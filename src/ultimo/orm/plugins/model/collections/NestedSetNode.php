<?php

namespace ultimo\orm\plugins\model\collections;

class NestedSetNode {
  public $element;
  public $parent;
  public $children = array();
  
  public function __construct($element, $parent = null) {
    $this->element = $element;
    $this->parent = $parent;
  }
  
  public function getDepth() {
    $depth = -1;
    $node = $this;
    while ($node !== null) {
      $depth++;
      $node = $node->parent;
    }
    
    return $depth;
  }
  
  public function getNodesAtDepth($depth) {
    if ($depth == 0) {
      return array($this);
    }
    
    $nodes = array();
    foreach ($this->children as $child) {
      $nodes = array_merge($nodes, $child->getNodesAtDepth($depth-1));
    }
    
    return $nodes;
  }
  
  public function hasChild($element) {
    $elementUniqueId = $element->getUniqueIdentifier();
    foreach ($this->children as $child) {
      if ($child->element->getUniqueIdentifier() == $elementUniqueId) {
        return true;
      }
    }
    return false;
  }
  
  public function hasDescendant($element) {
    if ($this->hasChild($element)) {
      return true;
    }
    
    foreach ($this->children as $child) {
      if ($child->hasDescendant($element)) {
        return true;
      }
    }
    
    return false;
  }
  
  public function isLeaf() {
    return empty($this->children);
  }
  
  public function getElementNode($element) {
    if ($this->element->getUniqueIdentifier() == $element->getUniqueIdentifier()) {
      return $this;
    }
    
    foreach ($this->children as $child) {
      $result = $child->getElementNode($element);
      if ($result !== null) {
        return $result;
      }
    }
    
    return null;
  }
  
  public function getPath() {
    $path = array();
    $node = $this;
    
    while ($node !== null) {
      array_unshift($path, $node);
      $node = $node->parent;
    }
    
    return $path;
  }
  
  public function flatten() {
    $set = array();
    
    $set[] = $this;
    foreach ($this->children as $child) {
      $set = array_merge($set, $child->flatten());
    }
    
    return $set;
  }

  static public function constructFromElements(array $elements) {
    $path = array();
    $pathLastIndex = -1;
    
    $root = null;
    
    foreach ($elements as $element) {

      // pop all elements until the parent is found
      while ($pathLastIndex >= 0 && $element->right > $path[$pathLastIndex]->element->right) {
        array_pop($path);
        $pathLastIndex--;
      }
      
      if ($pathLastIndex < 0) {
        $node = new NestedSetNode($element);
        $root = $node;
      } else {
        $node = new NestedSetNode($element, $path[$pathLastIndex]);
        $path[$pathLastIndex]->children[] = $node;
      }
      
      $path[] = $node;
      $pathLastIndex++;
    }
    
    return $root;
  }
  
  public function __toString() {
    $elems = array();
    
    $elems[] = str_repeat('-', $this->getDepth()+1) . ' ' . $this->element->__toString();
    foreach ($this->children as $child) {
      $elems[] = $child->__toString();
    }
    
    return implode("\n", $elems);
  }
  
}