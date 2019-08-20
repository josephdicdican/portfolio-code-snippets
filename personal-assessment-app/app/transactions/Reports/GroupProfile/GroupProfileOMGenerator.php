<?php

namespace App\Transactions\Reports\GroupProfile;

use App\Services\Traits\OrderCandidateTest\GetPercentilePerTestTypeTrait;
use App\Transactions\Reports\GroupProfile\GroupProfileGenerator;

class GroupProfileOMGenerator extends GroupProfileGenerator
{
    public function __construct($order_id, $inputs)
    {
        parent::__construct($order_id, $inputs);
    }

    public function prepareData()
    {
        $order = \Order::with('client')->find($this->order_id);

        if($this->test_type_id == \TestType::TEST_TYPE_OM_E_CN) { // force local to CN (to translate pdf report to chinese)
            \App::setLocale('cn');
        }

        $test_score_physical_needs_factors = \OrderCandidateTestScore::selectMainFields()
            ->addSelect(\DB::raw('avg(mean_score) as mean_score'))
            ->orderId($this->order_id)
            ->testId($this->input['test_id'])
            ->with(['question_code' => function($query) {
                $query->selectMainFields()->testTypeId($this->test_type_id)->noCustomNormId();
            }])
            ->name(\QuestionCode::OM_FACTORS[\QuestionCode::PHYSICAL_NEEDS])
            ->groupBy('name')->groupBy('group')
            ->orderBy('sequence_no')->get()->toArray();

        $test_score_material_welfare_factors = \OrderCandidateTestScore::selectMainFields()
            ->addSelect(\DB::raw('avg(mean_score) as mean_score'))
            ->orderId($this->order_id)
            ->testId($this->input['test_id'])
            ->with(['question_code' => function($query) {
                $query->selectMainFields()->testTypeId($this->test_type_id)->noCustomNormId();
            }])
            ->name(\QuestionCode::OM_FACTORS[\QuestionCode::MATERIAL_WELFARE])
            ->groupBy('name')->groupBy('group')
            ->orderBy('sequence_no')->get()->toArray();

        $test_score_interpersonal_relationships_factors = \OrderCandidateTestScore::selectMainFields()
            ->addSelect(\DB::raw('avg(mean_score) as mean_score'))
            ->orderId($this->order_id)
            ->testId($this->input['test_id'])
            ->with(['question_code' => function($query) {
                $query->selectMainFields()->testTypeId($this->test_type_id)->noCustomNormId();
            }])
            ->name(\QuestionCode::OM_FACTORS[\QuestionCode::INTERPERSONAL_RELATIONSHIPS])
            ->groupBy('name')->groupBy('group')
            ->orderBy('sequence_no')->get()->toArray();

        $test_score_self_actualisation_factors = \OrderCandidateTestScore::selectMainFields()
            ->addSelect(\DB::raw('avg(mean_score) as mean_score'))
            ->orderId($this->order_id)
            ->testId($this->input['test_id'])
            ->with(['question_code' => function($query) {
                $query->selectMainFields()->testTypeId($this->test_type_id)->noCustomNormId();
            }])
            ->name(\QuestionCode::OM_FACTORS[\QuestionCode::SELF_ACTUALISATION])
            ->groupBy('name')->groupBy('group')
            ->orderBy('sequence_no')->get()->toArray();

        $scorings = \Scoring::select(array(
                'id',
                'test_id',
                'test_type_id',
                'age_group_id',
                'sequence_no',
                'min_raw_score',
                'max_raw_score',
                'std_score',
                'banding',
                'question_code_id',
                'custom_id',
                'custom_id_type',
                'report_type',
                'gender',
                'report_desc',
                'report_questions',
                'suggested_development_guide',
            ))
            ->with(['question_code' => function($query) {
                $query->select(['id', 'group', 'name', 'description', 'custom_norm_id']);
            }])
            ->testTypeId($this->test_type_id)
            ->noTestId()->get()->toArray();

        $scoring_bandings = ['Very Low', 'Low', 'Moderately Low', 'Average', 'Moderately High', 'High', 'Very High'];

        // Factor: Physical Needs
        foreach($test_score_physical_needs_factors as $i => $test_score_physical_needs_factor) {
            $banding_percentile = GetPercentilePerTestTypeTrait::getOMPercentile($test_score_physical_needs_factor['mean_score']);
            $test_score_physical_needs_factor['banding'] = $banding_percentile['banding'];
            $test_score_physical_needs_factor['percentile'] = $banding_percentile['percentile'];

            $main_factor_scoring = array_first($scorings, function($key, $value) use ($test_score_physical_needs_factor) {
                if(
                    $value['question_code_id'] == $test_score_physical_needs_factor['question_code']['id']
                    && $value['banding'] == $test_score_physical_needs_factor['banding']
                ) {
                    return $value;
                }
            });
            $test_score_physical_needs_factor['question_code_group'] = $test_score_physical_needs_factor['question_code']['group'];
            $test_score_physical_needs_factor['scoring'] = $main_factor_scoring;
            $test_score_physical_needs_factor['scoring_banding_index'] = array_search($test_score_physical_needs_factor['banding'], $scoring_bandings);
            $test_score_physical_needs_factors[$i] = $test_score_physical_needs_factor;
        }

        // Factor: Material Welfare
        foreach($test_score_material_welfare_factors as $i => $test_score_material_welfare_factor) {
            $banding_percentile = GetPercentilePerTestTypeTrait::getOMPercentile($test_score_material_welfare_factor['mean_score']);
            $test_score_material_welfare_factor['banding'] = $banding_percentile['banding'];
            $test_score_material_welfare_factor['percentile'] = $banding_percentile['percentile'];

            $main_factor_scoring = array_first($scorings, function($key, $value) use ($test_score_material_welfare_factor) {
                if(
                    $value['question_code_id'] == $test_score_material_welfare_factor['question_code']['id']
                    && $value['banding'] == $test_score_material_welfare_factor['banding']
                ) {
                    return $value;
                }
            });
            $test_score_material_welfare_factor['question_code_group'] = $test_score_material_welfare_factor['question_code']['group'];
            $test_score_material_welfare_factor['scoring'] = $main_factor_scoring;
            $test_score_material_welfare_factor['scoring_banding_index'] = array_search($test_score_material_welfare_factor['banding'], $scoring_bandings);
            $test_score_material_welfare_factors[$i] = $test_score_material_welfare_factor;
        }

        // Factor: Interpersonal Relationship
        foreach($test_score_interpersonal_relationships_factors as $i => $test_score_interpersonal_relationships_factor) {
            $banding_percentile = GetPercentilePerTestTypeTrait::getOMPercentile($test_score_interpersonal_relationships_factor['mean_score']);
            $test_score_interpersonal_relationships_factor['banding'] = $banding_percentile['banding'];
            $test_score_interpersonal_relationships_factor['percentile'] = $banding_percentile['percentile'];

            $main_factor_scoring = array_first($scorings, function($key, $value) use ($test_score_interpersonal_relationships_factor) {
                if(
                    $value['question_code_id'] == $test_score_interpersonal_relationships_factor['question_code']['id']
                    && $value['banding'] == $test_score_interpersonal_relationships_factor['banding']
                ) {
                    return $value;
                }
            });
            $test_score_interpersonal_relationships_factor['question_code_group'] = $test_score_interpersonal_relationships_factor['question_code']['group'];
            $test_score_interpersonal_relationships_factor['scoring'] = $main_factor_scoring;
            $test_score_interpersonal_relationships_factor['scoring_banding_index'] = array_search($test_score_interpersonal_relationships_factor['banding'], $scoring_bandings);
            $test_score_interpersonal_relationships_factors[$i] = $test_score_interpersonal_relationships_factor;
        }

        // Factor: Self-Actualisation
        foreach($test_score_self_actualisation_factors as $i => $test_score_self_actualisation_factor) {
            $banding_percentile = GetPercentilePerTestTypeTrait::getOMPercentile($test_score_self_actualisation_factor['mean_score']);
            $test_score_self_actualisation_factor['banding'] = $banding_percentile['banding'];
            $test_score_self_actualisation_factor['percentile'] = $banding_percentile['percentile'];

            $main_factor_scoring = array_first($scorings, function($key, $value) use ($test_score_self_actualisation_factor) {
                if(
                    $value['question_code_id'] == $test_score_self_actualisation_factor['question_code']['id']
                    && $value['banding'] == $test_score_self_actualisation_factor['banding']
                ) {
                    return $value;
                }
            });
            $test_score_self_actualisation_factor['question_code_group'] = $test_score_self_actualisation_factor['question_code']['group'];
            $test_score_self_actualisation_factor['scoring'] = $main_factor_scoring;
            $test_score_self_actualisation_factor['scoring_banding_index'] = array_search($test_score_self_actualisation_factor['banding'], $scoring_bandings);
            $test_score_self_actualisation_factors[$i] = $test_score_self_actualisation_factor;
        }

        $f_report_type = "";
        switch($this->input['file_type']) {
            case 'gp-pdf-i':
                $report_type = 'profile';
                $file_ext = 'pdf';
                $f_report_type = 'IIR';
                break;
        }

        $is_cn_report = $this->test_type_id == \TestType::TEST_TYPE_OM_E_CN && \App::getLocale() == 'cn';
        $test_date = date(\Config::get('kcg.report_date'), strtotime($order->po_date));
        if($is_cn_report) {
            $test_date = formatDateToCN($order->po_date);
        }

        $this->pdf_data = [
            'test_type_id' => $this->test_type_id,
            'test_date' => $test_date,
            'client_name' => $order->client ? $order->client->name : '',
            'order_name' => $order->test_admin_name,
            'report_type' => $report_type,
            'test_score_physical_needs_factors' => $test_score_physical_needs_factors,
            'test_score_material_welfare_factors' => $test_score_material_welfare_factors,
            'test_score_interpersonal_relationships_factors' => $test_score_interpersonal_relationships_factors,
            'test_score_self_actualisation_factors' => $test_score_self_actualisation_factors,
            'test_score_banding_count' => count($scoring_bandings),
            'report_type' => $report_type,
        ];

        $test = \Test::find($this->input['test_id']);
        $test_abbreviation = $test ? $test->abbreviation : "";

        // filename: "{Test Abbrev} {Report Type: IIR,IPR...} {Group Name = Order Name} {PO Date}.{file_ext}"
        $this->filename = sprintf(
            "%s %s %s %s.%s",
            $test_abbreviation,
            $f_report_type,
            $order->test_admin_name,
            $order->po_date,
            $file_ext
        );
    }

    public function downloadReport()
    {
        if(!$this->pdf_data) {
            echo '<script type="text/javascript">'
            , 'alert("Error in downloading report: no test scores found.");'
            , 'history.go(-1);'
            , '</script>';
            return;
        }

        $is_cn_report = $this->test_type_id == \TestType::TEST_TYPE_OM_E_CN && \App::getLocale() == 'cn';
        $cn_prefix = $is_cn_report ? 'cn_' : '';
        // echo json_encode($this->pdf_data, JSON_UNESCAPED_UNICODE); die();
        $pdf = \PDF::loadView('reports.group_profiles.om.'.$cn_prefix.'om_'.$this->pdf_data['report_type'].'_group_profile_report', $this->pdf_data);
        $file_path = \Config::get('kcg.report_path');
        $this->filename = prepareValidFilenameLength(str_replace('/', '-', $this->filename));

        file_put_contents($file_path.$this->filename, $pdf->output());
        if(!array_key_exists('no_download', $this->input)) { // use $this->input['no_download'] to allow access from NrbCommand
            downloadFile($file_path, $this->filename, 'pdf');
        }
    }

    public function generateReport()
    {
        $this->prepareData();
        $this->downloadReport();
    }
}