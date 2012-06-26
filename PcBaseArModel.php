<?php
/**
 * PcBasicModel.php
 *
 */
class PcBaseArModel extends CActiveRecord {
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

		/* now, apply the locking condition which will protect again deletion if object was already updated by someone else */
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
	 * returns a substring of the one given, in length determined by $this->breadcrumbsStringLength
	 *
	 * @param string $str source string
	 * @return string trimmed, target string.
	 */
	public function trimStringForBreadcrumbs($str) {
		return substr($str, 0, $this->breadcrumbsStringLength) . "..";
	}
	/**
	 * This method should return the 'key' of the relation() method that relate the AR model to the 'users' table.
	 *
	 * Child classes should be able to return the relation name that relate this model to 'User' model (=users table)
	 * What is it good for? Sometimes, we will be handling AR models of unknown types, such as in the case of handling
	 * 'inappropriate content', or any other case in which we get the AR model but do not know its type in advance.
	 * In those cases, we'd like to cache the loaded AR records. Those AR objects relate (i.e. via
	 * relations() method) to the 'users' table and in since we cache, we need to eagerly load the author username as well.
	 * This is where this method comes in handy.
	 * By each AR class implementing this method we can ask for the class for the relation name, rather than knowing that
	 * in advance.
	 *
	 * For a working use case PcReportContent extension: PcReportContent._getContentCreatorUserId() method.
	 *
	 * @static
	 * @abstract
	 * @return string
	 */
	abstract public static function getCreatorRelationName();
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
