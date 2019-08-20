<?php

namespace App\Transactions\Reports;

/**
 * Group Profile in PDF
 *
 * Original Specs:
 *   The report will be the same as pre-existing individual reports, but report scores for candidates are the average scores of the group.
 *   The name of the group to be shown must be specified in the test order if this report is requested.
 */
class GenerateGroupProfileReportTransaction extends \NRBTransaction
{
    public static function generate($order_id, $input)
    {
        $order = \Order::find($order_id);
        if(!$order) return false;

        // determine handler of Group Profile
        $generator = self::determineGenerator($order_id, $input);

        if($generator) {
            $generator->generateReport();
        }

        // should throw error here not handled
    }

    /**
     * Returns correct GroupProfileGenerator per test_type_id
     * - each test should have 1 generator class to prepareData & downloadReport
     *
     * @param $test_type_id int
     * @param $test_scores array
     * @return GroupProfileGenerator
     */
    public static function determineGenerator($order_id, $input)
    {
        $test_type = \TestType::find($input['test_type_id']);
        $test_type_prefix = head(explode('-', $test_type->name));

        // GroupProfile{TestType}Generator
        $generator = sprintf(
            "App\\Transactions\\Reports\\GroupProfile\\GroupProfile%sGenerator",
            $test_type_prefix
        );

        if(class_exists($generator)) {
            return new $generator($order_id, $input);
        }

        return false;
    }
}