<?php

namespace App\Transactions\Reports\GroupProfile;

use Carbon\Carbon;
use App\Services\Interfaces\ITestTypeCAPConstants;
use App\Services\Traits\GenerateCAPReportFileTrait;
use App\Transactions\Reports\GroupProfile\GroupProfileGenerator;

/**
 * CAP Group Profile Generator
 *
 * prepares data and download report
 */
class GroupProfileCAPGenerator extends GroupProfileGenerator
{
    public function __construct($order_id, $inputs)
    {
        parent::__construct($order_id, $inputs);
    }

    /**
     * Prepares data for PDF report (handles IIR, on more soon)
     */
    public function prepareData()
    {
        $order = \Order::with('client')->find($this->order_id);

        if(!$this->computeOverallZScores()){
            return;
        }

        $computation = GenerateCAPReportFileTrait::prepareFactorSubfactorDescritors($this->test_scores, $this->test_type_id);
        extract($computation); // extracting index to variable (cap_factors, cap_subfactors, ...)

        $f_report_type = $report_type = $file_ext = '';
        switch($this->input['file_type']) {
            case 'gp-pdf-i': // IIR
                $report_type = 'profile';
                $file_ext = 'pdf';
                $f_report_type = 'IIR';
                break;
        }

        $this->pdf_data = array_merge($computation, [
            'test_type_id' => $this->test_type_id,
            'test_date' => $order->po_date,
            'client_name' => $order->client ? $order->client->name : '',
            'order_name' => $order->test_admin_name,
            'report_type' => $report_type,
        ]);

        $test_abbreviation = isset($this->test_scores[0]->test->abbreviation)
            ? $this->test_scores[0]->test->abbreviation : "";

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

    /**
     * Downloads PDF report
     */
    public function downloadReport()
    {
        if(!$this->pdf_data) {
            echo '<script type="text/javascript">'
            , 'alert("Error in downloading report: no test scores found.");'
            , 'history.go(-1);'
            , '</script>';
            return;
        }
        $this->pdf_data['report_version'] = 'EXTENDED VERSION';

        $pdf = \PDF::loadView('reports.group_profiles.cap_'.$this->pdf_data['report_type'].'_group_profile_report', $this->pdf_data);
        $file_path = \Config::get('kcg.report_path');
        $this->filename = prepareValidFilenameLength(str_replace('/', '-', $this->filename));

        file_put_contents($file_path.$this->filename, $pdf->output());
        if(!array_key_exists('no_download', $this->input)) { // use $this->input['no_download'] to allow access from NrbCommand
            downloadFile($file_path, $this->filename, 'pdf');
        }

        // echo json_encode($this->pdf_data, JSON_UNESCAPED_UNICODE); die();
    }

    public function generateReport()
    {
        $this->prepareData();
        $this->downloadReport();
    }
}