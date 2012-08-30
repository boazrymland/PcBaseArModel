<?php
/**
 * PcBasicModel.php
 *
 */
abstract class PcBaseArModel extends CActiveRecord {
	// attribute used to fascilitate optimistic locking. Or in other words - safe update/delete of records avoiding race conditions.
	const LOCKING_ATTRIBUTE = "lock_version";
	// updated on and created on attributes are used in a few locations below hence they're here as constants
	const CREATED_ON_ATTRIBUTE = "created_on";
	const UPDATED_ON_ATTRIBUTE = "updated_on";


	/* @var string $condition_string modified condition for optimistic locking */
	private $condition_string;

	/* @var array $originalAttributes used for dirty-ness checking. See below its usage... */
	protected $originalAttributes;

	/* @var int how many characters should be trimmed to (if needed) while displaying the title (or similar representative string)
	 * for breadcrumbs */
	public $breadcrumbsStringLength = 20;

	/**
	 * attaching behaviors.
	 *
	 * 1. automatic updating of timestamps:
	 *    1.1 Note that the updated timestamp is the output of NOW() run on the DB server!
	 *     1.2 Note the table column names are "created_on" and "udpated_on"
	 *
	 * @return array
	 *
	 *
	 */
	public function behaviors() {
		return array(
			'auto_timestamps' => array(
				'class' => 'zii.behaviors.CTimestampBehavior',
				'createAttribute' => self::CREATED_ON_ATTRIBUTE,
				'updateAttribute' => self::UPDATED_ON_ATTRIBUTE,
				'timestampExpression' => new CDbExpression('NOW()'),
			)
		);
	}

	/**
	 * Safely updates a record in "optimistic locking" concurrency control mode.
	 * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param array $attributes list of attributes (name=>$value) to be updated
	 * @param string $condition query condition
	 * @param array $params parameters to be bound to an SQL statement.
	 *
	 * @return integer always 1. On attempts that save that yielded num of affected rows != 1 a PcStaleObjectErrorException will be thrown
	 *
	 * @throws PcStaleObjectErrorException
	 *
	 * @see http://www.yiiframework.com/doc/api/1.1/CActiveRecord#updateByPk-detail
	 */
	public function safelyUpdateByPk($pk, array $attributes, $condition = '', $params = array()) {
		// first, check and explode if needed on incompatible condition given
		$this->explodeOnNonSupportedCondition($condition);

		/* now, apply the locking condition which will protect against update if the model was already updated by someone else */
		$this->applyLockingCondition($condition);

		//increment object version
		$lockingAttribute = self::LOCKING_ATTRIBUTE;
		$attributes[$lockingAttribute] = $this->$lockingAttribute + 1;

		/* using updateByPk() below, which we need for the condition, is not firing the CTimestampBehavior we're using in this base class.
		   Therefore, we need to manually update the updated_on timestamp. */
		$updated_on_attr = self::UPDATED_ON_ATTRIBUTE;
		$this->$updated_on_attr = new CDbExpression('NOW()');
		$attributes[$updated_on_attr] = $this->$updated_on_attr;

		$affectedRows = parent::updateByPk($pk, $attributes, $this->condition_string, $params);
		if ($affectedRows != 1) {
			throw new PcStaleObjectErrorException(Yii::t('PcBaseArModel', 'Data has been updated by another user so avoiding the update'));
		}
		// reflect the updated lock version in the model. It might be used down the road in the same request so it must to be
		// updated to reflect the updated lock version.
		$this->$lockingAttribute = $this->$lockingAttribute + 1;
		return $affectedRows;
	}

	/**
	 * Same as its parent AR deleteByPk() but checking version before actual deletion.
	 *
	 * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param string $condition query condition
	 * @param array $params parameters to be bound to an SQL statement.
	 * @return integer the number of rows deleted
	 *
	 * @throws PcStaleObjectErrorException
	 *
	 * @see http://www.yiiframework.com/doc/api/1.1/CActiveRecord#deleteByPk-detail
	 */
	public function safelyDeleteByPk($pk, $condition = '', $params = array()) {
		// first, check and explode if needed on incompatible condition given
		$this->explodeOnNonSupportedCondition($condition);

		/* now, apply the locking condition which will protect again deletion if object was already updated by someone else */
		$this->applyLockingCondition($condition);

		$affectedRows = parent::deleteByPk($pk, $condition, $params);
		if ($affectedRows != 1) {
			throw new PcStaleObjectErrorException(Yii::t('PcBaseArModel', 'Data has been updated by another user so avoiding deletion'));
		}
		return $affectedRows;
	}

	/**
	 * Adds a condition regarding an object version to an existing condition.
	 * @param string $condition Initial condition
	 *
	 * @TODO $condition in AR implementation is either a string or CDbCriteria. Make this parameter accept CDbCriteria as well. See http://www.yiiframework.com/doc/api/1.1/CActiveRecord#find-detail for more details on $condition.
	 */
	private function applyLockingCondition($condition = "") {

		$lockingAttribute = self::LOCKING_ATTRIBUTE;
		$expectedLockVersion = $this->$lockingAttribute;

		/*
		 * if safelyUpdate*...() is called several times in a single request, which is very much ok, this could cause a fatal bug since the
		 * $this->condition_string will be applied several times, causing a complete mess in the condition a total failure of the sql statement.
		 * Now, since during such several calls to update the model, the actual update is performed and the lock_version is advanced (by us) so
		 * to avoid this bug we need to calculate again the expected lock version and apply it in the condition.
		 */
		if (strpos($this->condition_string, "$lockingAttribute = ") !== false) {
			// condition already in. strip it. must be a reminiscence of past 'updates' to this model, in the same request
			$this->condition_string = preg_replace("/{$lockingAttribute} = \d+/", "", $this->condition_string);
			// strip any dangling "AND" words (the lock version condition is always concatenated at the end of the condition string so trim \
			// from end only)
			$this->condition_string = rtrim($this->condition_string, " ");
			$this->condition_string = preg_replace('/AND$/', '', $this->condition_string);
		}
		// add to an existing condition, if such exists:
		if (!empty($condition)) {
			$this->condition_string .= ' AND ';
		}
		$this->condition_string .= "$lockingAttribute = $expectedLockVersion";
	}

	/**
	 * Will throw exception if condition passed is not string. For now only string based conditions for the AR
	 * update/delete method are supported.
	 *
	 * @param mixed $condition the condition used.
	 * @throws PcBaseArModelUnsupportedConditionException
	 */
	private function explodeOnNonSupportedCondition($condition) {
		// prepare the log - get the type of given $condition
		if (is_object($condition)) {
			$type = get_class($condition);
		}
		else {
			$type = gettype($condition);
		}

		if (!is_string($condition)) {
			Yii::log("no support for CDbCreteria conditions yet. Only string (where clause...) is supported for now while I was passed condition of type $type.", CLogger::LEVEL_ERROR, __METHOD__);
			throw new PcBaseArModelUnsupportedConditionException(Yii::t('PcBaseArModel', "Only string based '\$condition' is supported while passed condition of type $type."));
		}
	}

	/**
	 * Attempt several times to update a record safely (utilizing optimistic locking). This can be useful in cases when you know
	 * that the data you are trying to save should overwrite any data that might have just been written.
	 * Num of attempts as well as timeout in between attempts are configurable.
	 *
	 * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param array $attributes list of attributes (name=>$value) to be updated
	 * @param integer $num_of_attempts
	 * @param integer $interval_between_attempts. In microseconds (1 million microseconds = 1 second).
	 * @param string $condition query condition
	 * @param array $params parameters to be bound to an SQL statement.
	 *
	 * @return mixed integer for the number of rows being updated or false if failed in all attempts
	 *
	 *
	 * @see safelyUpdateByPk() in this class
	 */
	public function safelyUpdateByPkWithRetry($pk, array $attributes, $num_of_attempts = 5, $interval_between_attempts = 500000, $condition = '', $params = array()) {
		$keep_trying = true;
		$attempt = 1;
		while ($keep_trying) {
			try {
				$affected_rows = $this->safelyUpdateByPk($pk, $attributes, $condition, $params);
				if ($affected_rows > 0) {
					// succeeded. no need to continue
					$keep_trying = false;
				}
				else {
					// check and determine break condition in case no exception thrown and still failed repeatedly.
					if ($attempt > $num_of_attempts) {
						$keep_trying = false;
					}
				}
			}
			catch (PcStaleObjectErrorException $e) {
				Yii::log("Catched PcStaleObjectErrorException in attempt #$attempt to safely update object of class " .
							get_class($this) . " with PK=" . var_export($pk, true) . ". Will try " . $num_of_attempts - $attempt .
							" more times with an internal of $interval_between_attempts microseconds between attempts.",
					CLogger::LEVEL_INFO,
					__METHOD__);
				$attempt++;
				// check and determine break condition
				if ($attempt > $num_of_attempts) {
					$keep_trying = false;
				}
				// sleep as directed to
				usleep($interval_between_attempts);
			}
		}
		if ($attempt > $num_of_attempts) {
			// exhausting ALL attempts and still failed.
			return false;
		}
		return $affected_rows;
	}

	/**
	 * Purposes:
	 *
	 * (1) enable "dirty-ness" checking by loading attributes right after 'load' so they can later be compares with attributes of 'live' object
	 *
	 *
	 */
	public function afterFind() {
		// log aside original attributes as they are later to be used to check for dirty-ness of 'this' object
		$this->originalAttributes = $this->attributes;
		parent::afterFind();
	}

	/**
	 *  Tells whether this model object has changed (comparing to when it was loaded from the DB).
	 *
	 * @return bool dirty or not.
	 *
	 */
	public function isDirty() {
		$diff = array_diff_assoc($this->originalAttributes, $this->attributes);
		if (count($diff) > 0) {
			return true;
		}
		return false;
	}

	/**
	 * Tells whether a specific attribute is changed in comparion to its original state once loaded from the DB.
	 *
	 * @param string $attribute_name
	 * @return bool dirty or not
	 */
	public function isAttributeDirty($attribute_name) {
		$temp1 = $this->originalAttributes[$attribute_name];
		$temp2 = $this->$attribute_name;
		// in the DB values can be null but those attributes when submitted unchanged in a form will be empty string,
		// hence our comparison below using "!=" and not "!=="
		if ($this->originalAttributes[$attribute_name] != $this->$attribute_name) {
			return true;
		}
		return false;
	}

	/**
	 * returns a substring of the one given, in length determined by $this->breadcrumbsStringLength
	 *
	 * @param string $str source string
	 * @return string trimmed, target string.
	 */
	public function trimStringForBreadcrumbs($str) {
		return substr($str, 0, $this->breadcrumbsStringLength) . "..";
	}
	/**
	 * This method tells whether a record with primary key $id exists or not.
	 * IMPORTANT NOTE: do NOT use this method for complex situations in which the PK is not a single, simple column value.
	 *
	 *
	 * @static
	 * @param mixed $pk_value this method is designed to accept anything here - null, false, int... (which is good since
	 *             this method could be run dynamically with unknown AR models, used for mere searching, for example).
	 * @return bool whether the record exists or not.
	 */
	public static function checkExists($pk_value) {
		// we use the following 3 statements only to enable child classes implement their own primaryKey()
		$model_name = get_called_class();
		$model = $model_name::model();
		$pk_name = $model->primaryKey();
		// check if a record exists using CActiveRecord.exists() . Its natural
		// to look for it, and I enjoyed finding it :)
		$exists_or_not = $model->exists("$pk_name=:pk", array(':pk' => $pk_value));
		return $exists_or_not;
	}

	/**
	 * Returns the primary key column name.
	 * @see http://www.yiiframework.com/doc/api/1.0/CActiveRecord#primaryKey()-detail - read it carefulyl to see that
	 *        it all depends on child classes implementation.
	 *
	 * @return string
	 */
	public function primaryKey() {
		return 'id';
	}

	/**
	 * This method should return the 'key' of the relation() method that relate the AR model to the 'users' table.
	 *
	 * Child classes should be able to return the relation name that relate this model to 'User' model (=users table)
	 * What is it good for? Sometimes, we will be handling AR models of unknown types, such as in the case of handling
	 * a report of an 'inappropriate content', or any other case in which we get the AR model but do not know its type
	 * in advance. In those cases, we'd like to cache the loaded AR records for future use of the same record. Those AR
	 * objects relate (i.e. via relations() method) to the 'users' table and in since we cache the objects, we need to
	 * eagerly load some of the creator details (like username) as well.
	 * This is where this method comes in handy.
	 *
	 * By forcing each AR class that extends this base class to implement this method we can ask for the extending class
	 * for the relation name, rather than knowing that in advance.
	 *
	 * For a working use case PcReportContent extension: PcReportContent._getContentCreatorUserId() method.
	 *
	 * If this method is irrelevant to your extending class either return null or you can throw an exception (whatever fits the
	 * case and the reasonable logic)
	 *
	 * @static
	 * @abstract
	 * @return string
	 */
	abstract public static function getCreatorRelationName();
	/**
	 * Method returns the model's creator user id. this is a common task that is useful. This method also caches the loaded
	 * object (or uses a cached object).
	 * Its highly recommended to use the following content for the method implementation within your model:
	 * > public static function getCreatorUserId($id) {
	 * >    $model = self::model()->cache(3600)->with(self::getCreatorRelationName())->findByPk($id);
	 * >    return $model->user_id;
	 * > }
	 * Notice that we cache the model with the (eagerly loaded) relating 'User' model. In some of my implementations I needed
	 * to load the username of the relating user. Using the same query here and in other locations enable actual re-use of
	 * the cached model. This way we get the entire relating user object at hand, of course at the
	 * price of possibly high cache usage - depending on your specific site. Consider lowering the lifetime used above (3600)
	 * if you exhaust your cache capacity.
	 *
	 * If this method is irrelevant to your extending class either return null or you can throw an exception (whatever fits the
	 * case and the reasonable logic)
	 *
	 * @static
	 * @abstract
	 *
	 * @param int $id the primary key for the model in question
	 * @return mixed
	 */
	abstract public static function getCreatorUserId($id);
}

/**
 * Class used to flag an error of unsupported "condition" passed to either of PcBaseArModel "safe" methods.
 *    The mentioned methods facilitate optimistic locking and support only limited condition type (only string and NOT CDbCreteria)
 *
 * @author Boaz Rymland
 */
class PcBaseArModelUnsupportedConditionException extends CDbException {
}

/**
 * PcStaleObjectError.php
 * Created on 28 04 2012 (10:03 PM)
 *
 * @author: boaz
 *
 */

class PcStaleObjectErrorException extends CDbException {

}
