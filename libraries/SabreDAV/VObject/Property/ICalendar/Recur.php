<?php

namespace Sabre\VObject\Property\ICalendar;

use Sabre\VObject\Property;
use Sabre\Xml;

/**
 * Recur property.
 *
 * This object represents RECUR properties.
 * These values are just used for RRULE and the now deprecated EXRULE.
 *
 * The RRULE property may look something like this:
 *
 * RRULE:FREQ=MONTHLY;BYDAY=1,2,3;BYHOUR=5.
 *
 * This property exposes this as a key=>value array that is accessible using
 * getParts, and may be set using setParts.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class Recur extends Property {

    /**
     * Updates the current value.
     *
     * This may be either a single, or multiple strings in an array.
     *
     * @param string|array $value
     *
     * @return void
     */
    public function setValue($value) {

        // If we're getting the data from json, we'll be receiving an object
        if ($value instanceof \StdClass) {
            $value = (array)$value;
        }

        if (is_array($value)) {
            $newVal = [];
            foreach ($value as $k => $v) {

                if (is_string($v)) {
                    $v = strtoupper($v);

                    // The value had multiple sub-values
                    if (strpos($v, ',') !== false) {
                        $v = explode(',', $v);
                    }
                    if (strcmp($k, 'until') === 0) {
                        $v = strtr($v, [':' => '', '-' => '']);
                    }
                } elseif (is_array($v)) {
                    $v = array_map('strtoupper', $v);
                }

                $newVal[strtoupper($k)] = $v;
            }
            $this->value = $newVal;
        } elseif (is_string($value)) {
            $this->value = self::stringToArray($value);
        } else {
            throw new \InvalidArgumentException('You must either pass a string, or a key=>value array');
        }

    }

    /**
     * Returns the current value.
     *
     * This method will always return a singular value. If this was a
     * multi-value object, some decision will be made first on how to represent
     * it as a string.
     *
     * To get the correct multi-value version, use getParts.
     *
     * @return string
     */
    public function getValue() {

        $out = [];
        foreach ($this->value as $key => $value) {
            $out[] = $key . '=' . (is_array($value) ? implode(',', $value) : $value);
        }
        return strtoupper(implode(';', $out));

    }

    /**
     * Sets a multi-valued property.
     *
     * @param array $parts
     * @return void
     */
    public function setParts(array $parts) {

        $this->setValue($parts);

    }

    /**
     * Returns a multi-valued property.
     *
     * This method always returns an array, if there was only a single value,
     * it will still be wrapped in an array.
     *
     * @return array
     */
    public function getParts() {

        return $this->value;

    }

    /**
     * Sets a raw value coming from a mimedir (iCalendar/vCard) file.
     *
     * This has been 'unfolded', so only 1 line will be passed. Unescaping is
     * not yet done, but parameters are not included.
     *
     * @param string $val
     *
     * @return void
     */
    public function setRawMimeDirValue($val) {

        $this->setValue($val);

    }

    /**
     * Returns a raw mime-dir representation of the value.
     *
     * @return string
     */
    public function getRawMimeDirValue() {

        return $this->getValue();

    }

    /**
     * Returns the type of value.
     *
     * This corresponds to the VALUE= parameter. Every property also has a
     * 'default' valueType.
     *
     * @return string
     */
    public function getValueType() {

        return 'RECUR';

    }

    /**
     * Returns the value, in the format it should be encoded for json.
     *
     * This method must always return an array.
     *
     * @return array
     */
    public function getJsonValue() {

        $values = [];
        foreach ($this->getParts() as $k => $v) {
            if (strcmp($k, 'UNTIL') === 0) {
                $date = new DateTime($this->root, null, $v);
                $values[strtolower($k)] = $date->getJsonValue()[0];
            } elseif (strcmp($k, 'COUNT') === 0) {
                $values[strtolower($k)] = intval($v);
            } else {
                $values[strtolower($k)] = $v;
            }
        }
        return [$values];

    }

    /**
     * This method serializes only the value of a property. This is used to
     * create xCard or xCal documents.
     *
     * @param Xml\Writer $writer  XML writer.
     *
     * @return void
     */
    protected function xmlSerializeValue(Xml\Writer $writer) {

        $valueType = strtolower($this->getValueType());

        foreach ($this->getJsonValue() as $value) {
            $writer->writeElement($valueType, $value);
        }

    }

    /**
     * Parses an RRULE value string, and turns it into a struct-ish array.
     *
     * @param string $value
     *
     * @return array
     */
    static function stringToArray($value) {

        $value = strtoupper($value);
        $newValue = [];
        foreach (explode(';', $value) as $part) {

            // Skipping empty parts.
            if (empty($part)) {
                continue;
            }
            list($partName, $partValue) = explode('=', $part);

            // The value itself had multiple values..
            if (strpos($partValue, ',') !== false) {
                $partValue = explode(',', $partValue);
            }
            $newValue[$partName] = $partValue;

        }

        return $newValue;
    }

    /**
     * Validates the node for correctness.
     *
     * The following options are supported:
     *   Node::REPAIR - May attempt to automatically repair the problem.
     *
     * This method returns an array with detected problems.
     * Every element has the following properties:
     *
     *  * level - problem level.
     *  * message - A human-readable string describing the issue.
     *  * node - A reference to the problematic node.
     *
     * The level means:
     *   1 - The issue was repaired (only happens if REPAIR was turned on)
     *   2 - An inconsequential issue
     *   3 - A severe issue.
     *
     * @param int $options
     *
     * @return array
     */
    public function validate($options = 0) {

        $repair = ($options & self::REPAIR);

        $warnings = parent::validate($options);
        $values = $this->getParts();

        foreach ($values as $key => $value) {

            if (empty($value)) {
                $warnings[] = [
                    'level'   => $repair ? 3 : 1,
                    'message' => 'Invalid value for ' . $key . ' in ' . $this->name,
                    'node'    => $this
                ];
                if ($repair) {
                    unset($values[$key]);
                }
            }

        }
        if (!isset($values['FREQ'])) {
            $warnings[] = [
                'level'   => $repair ? 3 : 1,
                'message' => 'FREQ is required in ' . $this->name,
                'node'    => $this
            ];
            if ($repair) {
                $this->parent->remove($this);
            }
        }
        if ($repair) {
            $this->setValue($values);
        }

        return $warnings;

    }

}
