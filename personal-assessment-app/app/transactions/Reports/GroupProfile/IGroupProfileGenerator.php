<?php

namespace App\Transactions\Reports\GroupProfile;

interface IGroupProfileGenerator
{
    /**
     * Prepares data for PDF reports
     */
    public function prepareData();

    /**
     * Downloads generated PDF (IIR, IPR, ...)
     */
    public function downloadReport();
}