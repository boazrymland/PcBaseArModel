PcBaseArModel
=============

Base "Active Record" model that adds capabilities to Yii's CActiveRecord

# Introduction & Features

This class is typically used as a base class to all your AR (Active Record) classes in a Yii project. It adds some nice and useful features:

1. Automatic timestamping when a record is being created and updated.
2. "Safe" updating and deleting of record using "Optimistic Locking".
  * Also method for safe updating using given number of retries in case of failures.
3. Convenient method for record attribute trimming for breadcrumbs. For example, 'title' attribute that needs to be trimmed since it can be long (common requirement since breadcrumbs have limited screen real-estate).
4. Can tell if an object 'is dirty'.

# Requirements

* Tested with Yii v1.1.9 and v1.1.10.

# Usage

## Installation

Extract the contents of this package. Place the extracted PcBaseArModel class inside /protected/components.

## DB changes required from extending classes

* Models that extend this class should have the following fields defined in their DB schema (MySQL syntax given):
  * `created_on` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
  * `updated_on` timestamp NULL DEFAULT NULL
  * `lock_version` int(11) DEFAULT '0'
* An example to a custom AR class that uses this class:

## Sample code

Should be as trivial as:

```php
class MyClass extends PcBaseArModel {
//...
}
```
After the above is done you cna use this class API as briefly described below.

## API 

After you have a model class that extends this class you can use its methods. Please refer to the code for complete documentation. Here's a list of public attributes and methods (and constants) to be aware of:

### Constants
* **LOCKING_ATTRIBUTE**: DB column name for holding the 'lock version' of the record (used for optimistic locking).
* **CREATED_ON_ATTRIBUTE**: DB column name for holding the 'created on' timestamp of the record.      
* **UPDATED_ON_ATTRIBUTE**: DB column name for holding the 'updated on' timestamp of the record.


### Attributes

* **breadcrumbsStringLength**: trimStringForBreadcrumbs($str) uses this parameter to trim the passed $str to this length.

### Methods

* **safelyUpdateByPk()**: Safely updates a record in optimistic lock mode. See code for full parameters list. This method is based on [CActiveRecord.updateByPk()](http://www.yiiframework.com/doc/api/1.1/CActiveRecord#updateByPk-detail)
* **safelyDeleteByPk()**: Safely deletes a record in optimistic lock mode. See code for full parameters list. This method is based on [CActiveRecord.deleteByuPk()](http://www.yiiframework.com/doc/api/1.1/CActiveRecord#deleteByPk-detail).
* **safelyUpdateByPkWithRetry()**: Simnilarly to safelyUpdateByPk() above, this method does the same but it will retry the update if the last one failed. Both the number of retries and the interval to wait in between attempts are configurable. 
* **isDirty()**: Would tell you if the object you're playing has changed since it was loaded from the DB or not.
* **trimStringForBreadcrumbs()**: Trims given strings according to length stated by $this->breadcrumbsStringLength and append ".." if was indeed trimmed.

### Exceptions thrown

The optimistic locking methods safelyUpdateByPk() and safelyDeleteByPk() can cause two types of exceptions to be throws. Your code should catch those exceptions and handle them according to your biz logic. The exceptions and their meanings are given below:
* **PcStaleObjectErrorException**. This exception is thrown if the object to be saved is determined to be 'old' when actual saving occurrs. This is a common way to implement the "safe" in the method's name - if its thrown it means that the object to be updated, from the moment the update request has been issued until the actual DB updating has been attempted - was already updated by someone else and if it was updaetd nevertheless - we would have overwritten the data just saved by someone else.
* **PcBaseArModelUnsupportedConditionException**. Both of the 'safely...' methods internally use CActiveRecord updateByPk() and deleteByPk() methods. As such, both accept as similar as possible parameter list. One of those parameters is 'condition'. This class' supports only string based 'condition' (as opposed to updateByPk() for example that receives a 'mixed' type condition parameter. See more [here](http://www.yiiframework.com/doc/api/1.1/CActiveRecord#updateByPk-detail) ). This exception is thrown in the event of an unsupported 'condition' passed to the relevant methods, as just described.