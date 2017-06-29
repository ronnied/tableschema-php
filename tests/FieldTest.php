<?php

namespace frictionlessdata\tableschema\tests;

use frictionlessdata\tableschema\DataSources\NativeDataSource;
use frictionlessdata\tableschema\Exceptions\DataSourceException;
use frictionlessdata\tableschema\Exceptions\FieldValidationException;
use frictionlessdata\tableschema\Schema;
use frictionlessdata\tableschema\SchemaValidationError;
use frictionlessdata\tableschema\Table;
use PHPUnit\Framework\TestCase;
use frictionlessdata\tableschema\Fields\FieldsFactory;

class FieldTest extends TestCase
{
    public $DESCRIPTOR_WITHOUT_TYPE;
    public $DESCRIPTOR_MIN;
    public $DESCRIPTOR_MAX;

    public function setUp()
    {
        $this->DESCRIPTOR_WITHOUT_TYPE = (object) [
            'name' => 'id',
        ];
        $this->DESCRIPTOR_MIN = (object) [
            'name' => 'id',
            'type' => 'string',
        ];
        $this->DESCRIPTOR_MAX = (object) [
            'name' => 'id',
            'type' => 'integer',
            'format' => 'default',
            'constraints' => (object) ['required' => true],
        ];
    }

    public function testNoValidFieldType()
    {
        try {
            FieldsFactory::field($this->DESCRIPTOR_WITHOUT_TYPE);
            $this->fail();
        } catch (FieldValidationException $e) {
            $this->assertEquals('Could not find a valid field for descriptor: {"name":"id"}', $e->getMessage());
        }
    }

    public function testDescriptor()
    {
        $this->assertEquals(
            $this->DESCRIPTOR_MIN,
            FieldsFactory::field($this->DESCRIPTOR_MIN)->descriptor()
        );
    }

    public function testName()
    {
        $this->assertEquals('id', FieldsFactory::field($this->DESCRIPTOR_MIN)->name());
    }

    public function testType()
    {
        $this->assertEquals('string', FieldsFactory::field($this->DESCRIPTOR_MIN)->type());
        $this->assertEquals('integer', FieldsFactory::field($this->DESCRIPTOR_MAX)->type());
    }

    public function testFormat()
    {
        $this->assertEquals('default', FieldsFactory::field($this->DESCRIPTOR_MIN)->format());
        $this->assertEquals('default', FieldsFactory::field($this->DESCRIPTOR_MAX)->format());
    }

    public function testConstraints()
    {
        $this->assertEquals((object) [], FieldsFactory::field($this->DESCRIPTOR_MIN)->constraints());
        $this->assertEquals((object) ['required' => true], FieldsFactory::field($this->DESCRIPTOR_MAX)->constraints());
    }

    public function testRequired()
    {
        $this->assertEquals(false, FieldsFactory::field($this->DESCRIPTOR_MIN)->required());
        $this->assertEquals(true, FieldsFactory::field($this->DESCRIPTOR_MAX)->required());
    }

    public function testCastValue()
    {
        $this->assertEquals(1, FieldsFactory::field($this->DESCRIPTOR_MAX)->castValue('1'));
    }

    public function testCastValueConstraintError()
    {
        try {
            FieldsFactory::field($this->DESCRIPTOR_MAX)->castValue('');
            $this->fail();
        } catch (FieldValidationException $e) {
            $this->assertEquals('id: field is required ()', $e->getMessage());
        }
    }

    public function testDisableConstraints()
    {
        $this->assertEquals(
            null,
            FieldsFactory::field($this->DESCRIPTOR_MIN)->disableConstraints()->castValue('')
        );
        $this->assertEquals(
            null,
            FieldsFactory::field($this->DESCRIPTOR_MAX)->disableConstraints()->castValue('')
        );
    }

    public function testCastValueNullMissingValues()
    {
        // missing values are only validated at schema castRow function
        $schema = new Schema((object) [
            'fields' => [
                (object) ['name' => 'name', 'type' => 'number'],
            ],
            'missingValues' => ['null'],
        ]);
        $this->assertEquals(['name' => null], $schema->castRow(['name' => 'null']));
    }

    public function testValidateValue()
    {
        $this->assertFieldValidateValue('', $this->DESCRIPTOR_MAX, '1');
        $this->assertFieldValidateValue('id: value must be numeric (string)', $this->DESCRIPTOR_MAX, 'string');
        $this->assertFieldValidateValue('id: field is required ()', $this->DESCRIPTOR_MAX, '');
    }

    public function testValidateValueDisableConstraints()
    {
        $this->assertEquals([], FieldsFactory::field($this->DESCRIPTOR_MIN)->disableConstraints()->validateValue(''));
        $this->assertEquals([], FieldsFactory::field($this->DESCRIPTOR_MAX)->disableConstraints()->validateValue(''));
    }

    public function testStringMissingValues()
    {
        $this->assertMissingValues(['type' => 'string'], ['', 'NA', 'N/A']);
    }

    public function testNumberMissingValues()
    {
        $this->assertMissingValues(['type' => 'number'], ['', 'NA', 'N/A']);
    }

    public function testValidateValueRequired()
    {
        $schema = new Schema((object) [
            'fields' => [
                (object) [
                    'name' => 'name',
                    'type' => 'string',
                    'constraints' => (object) ['required' => true],
                ],
            ],
            'missingValues' => ['', 'NA', 'N/A'],
        ]);
        $this->assertSchemaValidateValue('', $schema, 'test');
        $this->assertSchemaValidateValue('', $schema, 'null');
        $this->assertSchemaValidateValue('', $schema, 'none');
        $this->assertSchemaValidateValue('', $schema, 'nil');
        $this->assertSchemaValidateValue('', $schema, 'nan');
        $this->assertSchemaValidateValue('name: field is required ()', $schema, 'NA');
        $this->assertSchemaValidateValue('name: field is required ()', $schema, 'N/A');
        $this->assertSchemaValidateValue('', $schema, '-');
        $this->assertSchemaValidateValue('name: field is required ()', $schema, '');
        $this->assertSchemaValidateValue('name: field is required ()', $schema, null);
    }

    public function testValidateValuePattern()
    {
        $descriptor = (object) [
            'name' => 'name',
            'type' => 'string',
            'constraints' => (object) ['pattern' => '3.*'],
        ];
        $this->assertFieldValidateValue('', $descriptor, '3');
        $this->assertFieldValidateValue('', $descriptor, '321');
        $this->assertFieldValidateValue('name: value does not match pattern (123)', $descriptor, '123');
    }

    public function testValidateValueUnique()
    {
        // unique values are only validated at the Table object
        $dataSource = new NativeDataSource([
            ['name' => 1],
            ['name' => 2],
            ['name' => 2],
            ['name' => 4],
        ]);
        $schema = new Schema((object) [
            'fields' => [
                (object) [
                    'name' => 'name',
                    'type' => 'integer',
                    'constraints' => (object) ['unique' => true],
                ],
            ],
        ]);
        $table = new Table($dataSource, $schema);
        $actualRows = [];
        try {
            foreach ($table as $row) {
                $actualRows[] = $row;
            }
            $this->fail();
        } catch (DataSourceException $e) {
            $this->assertEquals([
                ['name' => 1],
                ['name' => 2],
            ], $actualRows);
            $this->assertEquals('row 3: field must be unique', $e->getMessage());
        }
    }

    public function testValidateValueEnum()
    {
        $descriptor = (object) [
            'name' => 'name',
            'type' => 'integer',
            'constraints' => (object) ['enum' => ['1', '2', 3]],
        ];
        $this->assertFieldValidateValue('', $descriptor, '1');
        $this->assertFieldValidateValue('', $descriptor, 2);
        $this->assertFieldValidateValue('', $descriptor, '3');
        $this->assertFieldValidateValue('name: value not in enum (4)', $descriptor, '4');
        $this->assertFieldValidateValue('name: value not in enum (4)', $descriptor, 4);
    }

    public function testValidateValueMinimum()
    {
        $descriptor = (object) [
            'name' => 'name',
            'type' => 'integer',
            'constraints' => (object) ['minimum' => 1],
        ];
        $this->assertFieldValidateValue('', $descriptor, '2');
        $this->assertFieldValidateValue('', $descriptor, 2);
        $this->assertFieldValidateValue('', $descriptor, '1');
        $this->assertFieldValidateValue('', $descriptor, 1);
        $this->assertFieldValidateValue('name: value is below minimum (0)', $descriptor, '0');
        $this->assertFieldValidateValue('name: value is below minimum (0)', $descriptor, 0);
    }

    public function testValidateValueMaximum()
    {
        $descriptor = (object) [
            'name' => 'name',
            'type' => 'integer',
            'constraints' => (object) ['maximum' => 1],
        ];
        $this->assertFieldValidateValue('', $descriptor, '0');
        $this->assertFieldValidateValue('', $descriptor, 0);
        $this->assertFieldValidateValue('', $descriptor, '1');
        $this->assertFieldValidateValue('', $descriptor, 1);
        $this->assertFieldValidateValue('name: value is above maximum (2)', $descriptor, '2');
        $this->assertFieldValidateValue('name: value is above maximum (2)', $descriptor, 2);
    }

    public function testValidateValueMinLength()
    {
        $descriptor = (object) [
            'name' => 'name',
            'type' => 'string',
            'constraints' => (object) ['minLength' => 2],
        ];
        $this->assertFieldValidateValue('', $descriptor, 'ab');
        $this->assertFieldValidateValue('', $descriptor, 'aaaa');
        // null value passes (because field is not required)
        $this->assertFieldValidateValue('', $descriptor, null);
        $this->assertFieldValidateValue('name: value is below minimum length (a)', $descriptor, 'a');
    }

    public function testValidateValueMaxLength()
    {
        $descriptor = (object) [
            'name' => 'name',
            'type' => 'string',
            'constraints' => (object) ['maxLength' => 2],
        ];
        $this->assertFieldValidateValue('', $descriptor, 'ab');
        $this->assertFieldValidateValue('', $descriptor, 'a');
        $this->assertFieldValidateValue('', $descriptor, null);
        $this->assertFieldValidateValue('', $descriptor, '');
        $this->assertFieldValidateValue('name: value is above maximum length (aaa)', $descriptor, 'aaa');
    }

    protected function assertFieldValidateValue($expectedErrors, $descriptor, $value)
    {
        $this->assertEquals(
            $expectedErrors,
            SchemaValidationError::getErrorMessages(FieldsFactory::field($descriptor)->validateValue($value))
        );
    }

    /**
     * @param $expectedErrors
     * @param $schema Schema
     * @param $value
     */
    protected function assertSchemaValidateValue($expectedErrors, $schema, $value)
    {
        $this->assertEquals(
            $expectedErrors,
            SchemaValidationError::getErrorMessages($schema->validateRow(['name' => $value]))
        );
    }

    protected function assertMissingValues($partialDescriptor, $missingValues)
    {
        $descriptor = (object) ['name' => 'name'];
        foreach ($partialDescriptor as $k => $v) {
            $descriptor->{$k} = $v;
        }
        $schema = new Schema((object) [
            'fields' => [$descriptor],
            'missingValues' => $missingValues,
        ]);
        foreach ($missingValues as $val) {
            $this->assertEquals(['name' => null], $schema->castRow(['name' => $val]));
        }
    }
}