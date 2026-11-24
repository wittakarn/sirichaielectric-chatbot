<?php
/**
 * Real product search, fake quotation: the shop endpoint saves every quotation
 * to the production DB, so tests must not call it.
 */
require_once __DIR__ . '/../services/ProductAPIService.php';

class MockQuotationProductAPI extends ProductAPIService {
    /** @var array Every generateFastQuotation() call: quotaDetail, priceType, remark */
    public $quotationCalls = array();

    public function generateFastQuotation($quotaDetail, $priceType, $remark = '') {
        $this->quotationCalls[] = array('quotaDetail' => $quotaDetail, 'priceType' => $priceType, 'remark' => $remark);
        error_log('[MockQuotation] priceType=' . $priceType . ' remark=' . $remark . ' items=' . json_encode($quotaDetail, JSON_UNESCAPED_UNICODE));
        $n = count($this->quotationCalls);
        return json_encode(array(
            'quotNo' => '2569/TEST' . $n,
            'pdfUrl' => 'https://shop.sirichaielectric.com/configuration/server/report/temp/fast-quot-test-' . $n . '.pdf',
        ));
    }
}
