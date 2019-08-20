<?php

namespace App\Transactions\Reports\GroupProfile;

use App\Transactions\Reports\GroupProfile\IGroupProfileGenerator;

class GroupProfileGenerator extends \NRBTransaction implements IGroupProfileGenerator
{
    public $order_id;
    public $input;
    public $test_type_id;
    public $test_scores;
    public $pdf_data;
    public $filename;
    public $use_avg_score = true; // determine if will use avg(mean_score) or manual recalculation via avg(avg_score)

    public function __construct($order_id, $input)
    {
        $this->order_id = $order_id;
        $this->input = $input;
        $this->test_type_id = isset($input['test_type_id']) ? $input['test_type_id'] : null;
        $this->test_scores = [];
        $this->pdf_data = [];
        $this->filename = '';
    }

    /**
     * Default behavior: Computes final z_score to be used for preparing data for PDF report
     *  can be overriden to handle other types of Scoring computation (like ACT not same with OPA)
     *
     * @return array [[
     *  group, name, sequence_no, avg_score, mean_score,
     *  question_code <-- test_type_id needed to get actual linked question_code
     * ]]
     */
    public function computeOverallZScores()
    {
        // ## Get average of all subfactors' avg_scores, then compute z-score

        // Step 1. Get all average of avg_scores per factor/subfactor
        $order_candidate_test_scores = $this->getOrderCandidateTestScores();

        // Step 2. Compute Z Scores using question_code's mean & standard_deviation
        if($order_candidate_test_scores && $order_candidate_test_scores->toArray()) {
            foreach($order_candidate_test_scores as $key => $test_score) {
                // override mean_score to use same variable when getting scorings
                if($this->use_avg_score) { // if use average, recalculate mean_score else as is (mean_score being averaged)
                    $test_score->mean_score = isset($test_score->question_code->mean) && isset($test_score->question_code->standard_deviation)
                        ? (
                            $test_score->question_code->standard_deviation > 0 ?
                                ($test_score->average_avg_score - $test_score->question_code->mean) / $test_score->question_code->standard_deviation
                                : 0
                        ) : null;
                }
                // need to update $test_score->banding from the new computed $test_score->mean_score (or it will be same to 1 candidate record)
                $scoring = $test_score->question_code
                    ? \Scoring::questionCodeId($test_score->question_code->id)
                        ->testTypeId($this->test_type_id)
                        ->rawScoreBetween($test_score->mean_score)
                        ->orderBy('min_raw_score')
                        ->first() : false;

                if($scoring) {
                    $test_score->banding = $scoring->banding;
                    $test_score->std_score = $scoring->std_score;
                    $test_score->percentile = $scoring->percentile; // added as per @see OrderCandidateTestTransaction@endtest
                }
                $order_candidate_test_scores[$key] = $test_score;
            }

            $this->test_scores = $order_candidate_test_scores;
            return true;
        }

        return false;
    }

    public function getOrderCandidateTestScores()
    {
        $scores = \OrderCandidateTestScore::select(
                \DB::raw("*, ".($this->use_avg_score ? "avg(avg_score) as average_avg_score" : "avg(mean_score) as mean_score"))
            )
            ->with([
                'test' => function($query) {
                    $query->select(['id', 'name', 'abbreviation']);
                },
                'question_code' => function($query) {
                    $query->selectMainFields()
                        ->testTypeId($this->test_type_id)
                        ->noCustomNormId();
                }
            ])
            ->orderId($this->order_id)
            ->testId($this->input['test_id']);

        if(\TestType::isTestTypeOPA($this->test_type_id)) {
            $scores = $scores->notCompetencies();
        }

        return $scores
            ->groupBy('name')->groupBy('group')
            ->orderBy('order_candidate_test_id')
            ->orderBy('sequence_no')
            ->get();
    }

    // methods intended to be handled in the type generator
    public function prepareData() {}
    public function downloadReport() {}
}