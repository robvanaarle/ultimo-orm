# Ultimo Orm
Object relational mapper

## Requirements
* PHP 5.3
* PDO

## Usage
Documentation is not much right now, but here is some sample usage

    $manager = new \ultimo\orm\Manager($pdoConnection);
	$manager->associateModel('info_infoitem', 'Infoitem', 'infomodule\modules\Infoitem');
	$infoitem = $manager->Infoitem->withImages()->getById(42);


### Infoitem model

	namespace infomodule\models;
	
	class Infoitem extends \ultimo\orm\Model {
	  
	  public $id;
	  public $category_id;
	  public $title = '';
	  public $body = '';
	  
	  public $category;
	  public $images = array();
	  
	  static protected $fields = array('id', 'category_id', 'title', 'body');
	  static protected $primaryKey = array('id');
	  static protected $autoIncrementField = 'id';
	  static protected $relations = array(
	    'category' => array('Category', array('category_id' => 'id'), self::MANY_TO_ONE),
	    'images' => array('InfoitemImage', array('id' => 'visualised_id'), self::ONE_TO_MANY)
	  );
	  static protected $scopes = array('withImages', 'fromCategory', 'orderByCategoryId', 'withCategory');
	  static protected $plugins = array('Sequence', 'Timestamps');
	  static public $_sequenceGroupFields = array('category_id');
	  
	  public function slug() {
	    return "slug_of_" . $this->title;
	  }
	  
	  static public function withImages() {
	    return function ($q) {
	      $q->with('@images')
	        ->order('@images.id', 'ASC');
	    };
	  }
	  
	  static public function withCategory() {
	    return function ($q) {
	      $q->with('@category');
	    };
	  }
	  
	  static public function fromCategory($category_id) {
	    return function ($q) use ($category_id) {
	      $q->where('@category_id = ?', array($category_id));
	    };
	  }
	  
	  static public function orderByCategoryId() {
	    return function ($q) {
	      $q->order('@category_id', 'ASC');
	    };
	  }
	}
	