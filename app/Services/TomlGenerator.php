<?php

namespace App\Services;

use Carbon\Carbon;
use Yosymfony\Toml\TomlBuilder;

class TomlGenerator
{
    public function generateToml(array $tomlArray, string $fileName)
    {
        $tomlBuilder = new TomlBuilder();

        // Add comments on the top of fly.toml
        $tomlBuilder->addComment("fly.toml app configuration auto-generated on " . Carbon::now());
        $tomlBuilder->addComment("");
        $tomlBuilder->addComment("See https://fly.io/docs/reference/configuration/ for information about how to use this file.");
        $tomlBuilder->addComment("");

        foreach($tomlArray as $key => $value)
        {
            $this->buildFunction($key, $value, $tomlBuilder);
        }

        file_put_contents($fileName, $tomlBuilder->getTomlString());
    }

    private function buildFunction(string $key, mixed $value, TomlBuilder $builder, string $groupPrefix = "", bool $arrayOfTables = false)
    {
        // Check if $value is an array or not. If not, add simple value. If so, continue.
        if (!is_array($value))
        {
            $builder->addValue($key, $value);
        }
        else
        {
            // Check if the array is an indexed array (array_is_list==true) or not.
            // indexed array: [value1, value2] -> php will add indexes in here -> [0 => value1, 1 => value2]
            // associative array: ['key1' => value1, 'key2' => value2]
            if (array_is_list($value))
            {
                // Check if the array is multidimensional or not. If so, loop over its elements. If not, add the array as a simple value: key = []
                if (count($value) !== count($value, COUNT_RECURSIVE))
                {
                    //$value is a multidimensional indexed array --> For each element, create an array of tables with the current key: [[$key]]
                    // This is because we don't want 0 and 1 as keys, so we use the current key for each value in the array.
                    // this turns 'services' => [0 => value0, 1 => value1] into ['services' => value0], ['services' => value1]
                    // finally, this would result in two [[services]] tags in the fly.toml.
                    foreach($value as $key2 => $value2)
                    {
                        $this->buildFunction($key, $value2, $builder, $groupPrefix, true);
                    }
                }
                else
                {
                    // Array is a one-dimensional indexed array --> add simple value: key = []
                    $builder->addValue($key, $value);
                }
            }
            else
            {
                // array is an associative array, which means it has keys and values: ['key1' => value1, 'key2' => value2]
                if ($arrayOfTables)
                {
                    // this means we're coming from a multidimensional indexed array, see above for an example
                    $builder->addArrayOfTable($groupPrefix . $key);
                }
                else
                {
                    $builder->addTable($groupPrefix . $key);
                }

                if (count($value) !== count($value, COUNT_RECURSIVE))
                {
                    // array is multidimensional-> add $key to $groupPrefix for use later on.
                    $groupPrefix = $groupPrefix . "$key.";
                }

                // finally, loop over the array.
                foreach($value as $key2 => $value2)
                {
                    $this->buildFunction($key2, $value2, $builder, $groupPrefix, false);
                }
            }
        }
    }
}
