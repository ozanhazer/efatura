<?php

namespace furkankadioglu\eFatura;

use furkankadioglu\eFatura\Exceptions\ApiException;
use furkankadioglu\eFatura\Exceptions\NullDataException;
use furkankadioglu\eFatura\Exceptions\TestEnvironmentException;
use furkankadioglu\eFatura\Models\Invoice;
use furkankadioglu\eFatura\Models\UserInformations;
use GuzzleHttp\Client;
use Mpdf\Mpdf;
use Ramsey\Uuid\Uuid;

class InvoiceManager
{
    /**
     * Api Urls
     */
    const BASE_URL = "https://earsivportal.efatura.gov.tr";

    const TEST_URL = "https://earsivportaltest.efatura.gov.tr";

    /**
     * Api Paths
     */
    const DISPATCH_PATH = "/earsiv-services/dispatch";

    const TOKEN_PATH = "/earsiv-services/assos-login";

    const REFERRER_PATH = "/intragiris.html";

    protected string $username;

    protected string $password;

    /**
     * Guzzle client
     */
    protected Client $client;

    /**
     * Session Token
     */
    protected string $token;

    /**
     * Language
     */
    protected string $language = "TR";

    /**
     * Current targeted invoice
     */
    protected Invoice $invoice;

    /**
     * Referrer variable
     */
    protected string $referrer;

    /**
     * Debug mode
     */
    protected bool $debugMode = false;

    /**
     * Invoices
     *
     * @var Invoice[]
     */
    protected $invoices = [];

    /**
     * User Informations
     */
    protected UserInformations $userInformations;

    /**
     * Operation identifier for SMS Verification
     */
    protected string $oid;

    /**
     * Base headers
     */
    protected $headers = [
        "accept" => "*/*",
        "accept-language" => "tr,en-US;q=0.9,en;q=0.8",
        "cache-control" => "no-cache",
        "content-type" => "application/x-www-form-urlencoded;charset=UTF-8",
        "pragma" => "no-cache",
        "sec-fetch-mode" => "cors",
        "sec-fetch-site" => "same-origin",
        "User-Agent" => "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.67 Safari/537.36", // Dummy UA
    ];

    public function __construct()
    {
        $this->referrer = $this->getBaseUrl() . self::REFERRER_PATH;
        $this->headers["referrer"] = $this->referrer;

        $this->client = new Client($this->headers);
    }

    public function setUsername(string $username)
    {
        $this->username = $username;

        return $this;
    }

    public function setDebugMode(bool $status)
    {
        $this->debugMode = $status;

        return $this;
    }

    public function setPassword(string $password)
    {
        $this->password = $password;

        return $this;
    }

    public function setTestCredentials()
    {
        $response = $this->client->post($this->getBaseUrl() . "/earsiv-services/esign", [
            "form_params" => [
                "assoscmd" => "kullaniciOner",
                "rtype" => "json",
            ],
        ]);
        $body = json_decode($response->getBody(), true);

        $this->checkError($body);

        if (isset($body["userid"]) and $body["userid"] == "") {
            throw new TestEnvironmentException("eArsiv test kullanıcısı alınamadı. Lütfen daha sonra deneyin.");
        }

        $this->username = $body["userid"];
        $this->password = "1";

        return $this;
    }

    /**
     * Setter function for all credentials
     *
     * @param string $username
     * @param string $password
     *
     * @return self
     */
    public function setCredentials(string $username, string $password)
    {
        $this->username = $username;
        $this->password = $password;

        return $this;
    }

    /**
     * Setter function for token
     *
     * @param string $token
     *
     * @return self
     */
    public function setToken(string $token)
    {
        $this->token = $token;

        return $this;
    }

    /**
     * Getter function for token
     *
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Connect with credentials
     *
     * @return self
     */
    public function connect()
    {
        $this->getTokenFromApi();

        return $this;
    }

    /**
     * Get all credentials as an array
     *
     * @return array
     */
    public function getCredentials()
    {
        return [
            $this->username,
            $this->password,
        ];
    }

    /**
     * Get base url
     *
     * @return string
     */
    public function getBaseUrl()
    {
        if ($this->debugMode) {
            return self::TEST_URL;
        }

        return self::BASE_URL;
    }

    /**
     * Send request, json decode and return response
     *
     * @param string     $url
     * @param array      $parameters
     * @param array|null $headers
     *
     * @return array
     */
    private function sendRequestAndGetBody(string $url, array $parameters, array $headers = null)
    {
        $response = $this->client->post($this->getBaseUrl() . "$url", [
            "headers" => $headers ?: $this->headers,
            "form_params" => $parameters,
        ]);

        return json_decode($response->getBody(), true);
    }

    /**
     * Get auth token
     *
     * @return string
     */
    public function getTokenFromApi()
    {
        $parameters = [
            "assoscmd" => $this->debugMode ? "login" : "anologin",
            "rtype" => "json",
            "userid" => $this->username,
            "sifre" => $this->password,
            "sifre2" => $this->password,
            "parola" => "1",
        ];

        $body = $this->sendRequestAndGetBody(self::TOKEN_PATH, $parameters, []);
        $this->checkError($body);

        return $this->token = $body["token"];
    }

    /**
     * Logout from API
     *
     * @return bool
     */
    public function logOutFromAPI()
    {
        $parameters = [
            "assoscmd" => "logout",
            "rtype" => "json",
            "token" => $this->token,
        ];

        $body = $this->sendRequestAndGetBody(self::TOKEN_PATH, $parameters, []);
        $this->checkError($body);
        $this->token = null;

        return true;
    }

    /**
     * Check error, if exist throw it!
     *
     * @param array $jsonData
     *
     * @return void
     */
    private function checkError(array $jsonData)
    {
        if (isset($jsonData["error"])) {
            throw new ApiException("Sunucu taraflı bir hata oluştu!", 0, null, $jsonData);
        }
    }

    /**
     * Setter function for invoice
     *
     * @param Invoice $invoice
     *
     * @return self
     */
    public function setInvoice(Invoice $invoice)
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function getInvoices()
    {
        return $this->invoices;
    }

    /**
     * Get company name from tax number via api
     *
     * @param string $taxNr
     *
     * @return array
     */
    public function getCompanyInfo(string $taxNr)
    {
        $parameters = [
            "cmd" => "SICIL_VEYA_MERNISTEN_BILGILERI_GETIR",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_BASITFATURA",
            "token" => $this->token,
            "jp" => '{"vknTcknn":"' . $taxNr . '"}',
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);
        $this->checkError($body);

        return $body;
    }

    /**
     * Get invoices from api
     *
     * @param string $startDate
     * @param string $endDate
     *
     * @return array
     */
    public function getInvoicesFromAPI(string $startDate, string $endDate)
    {
        $parameters = [
            "cmd" => "EARSIV_PORTAL_TASLAKLARI_GETIR",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_BASITTASLAKLAR",
            "token" => $this->token,
            "jp" => '{"baslangic":"' . $startDate . '","bitis":"' . $endDate . '","hangiTip":"5000/30000", "table":[]}',
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);
        $this->checkError($body);

        // Array tipinden verilen tarih aralığında yer alan faturalar dönüyor
        $this->invoices = $body['data'];

        return $body;
    }

    /**
     * Get main three menu from api
     *
     * @return array
     */
    public function getMainTreeMenuFromAPI()
    {

        $headers = [
            "referrer" => $this->referrer,
        ];

        $parameters = [
            "cmd" => "getUserMenu",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "MAINTREEMENU",
            "token" => $this->token,
            "jp" => '{"ANONIM_LOGIN":"1"}',
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters, $headers);
        $this->checkError($body);

        return $body["data"];
    }

    /**
     * Create draft basic invoice
     *
     * @param \furkankadioglu\eFatura\Models\Invoice|null $invoice
     *
     * @return self
     */
    public function createDraftBasicInvoice(Invoice $invoice = null)
    {
        if ($invoice != null) {
            $this->invoice = $invoice;
        }

        if ($this->invoice == null) {
            throw new NullDataException("Invoice variable not exist");
        }

        $parameters = [
            "cmd" => "EARSIV_PORTAL_FATURA_OLUSTUR",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_BASITFATURA",
            "token" => $this->token,
            "jp" => "" . json_encode($this->invoice->export()) . "",
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);
        $this->checkError($body);

        if ($body["data"] != "Faturanız başarıyla oluşturulmuştur. Düzenlenen Belgeler menüsünden faturanıza ulaşabilirsiniz.") {
            throw new ApiException("Fatura oluşturulamadı.", 0, null, $body);
        }

        return $this;
    }

    /**
     * Get html invoice
     *
     * @param \furkankadioglu\eFatura\Models\Invoice|null $invoice
     * @param bool                                        $signed
     *
     * @return string
     */
    public function getInvoiceHTML(Invoice $invoice = null, bool $signed = true)
    {
        if ($invoice != null) {
            $this->invoice = $invoice;
        }

        if ($this->invoice == null) {
            throw new NullDataException("Invoice variable not exist");
        }

        $data = [
            "ettn" => $this->invoice->getUuid(),
            "onayDurumu" => $signed ? "Onaylandı" : "Onaylanmadı",
        ];

        $parameters = [
            "cmd" => "EARSIV_PORTAL_FATURA_GOSTER",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_TASLAKLAR",
            "token" => $this->token,
            "jp" => "" . json_encode($data) . "",
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);
        $this->checkError($body);

        return $body["data"];
    }

    /**
     * PDF Export
     *
     * @param \furkankadioglu\eFatura\Models\Invoice|null $invoice
     * @param boolean                                     $signed
     *
     * @return string|void
     */
    public function getInvoicePDF(Invoice $invoice = null, bool $signed = true)
    {
        $data = $this->getInvoiceHTML($invoice, $signed);
        $mpdf = new Mpdf();
        $mpdf->WriteHTML($data);

        return $mpdf->Output();
    }

    /**
     * Cancel an invoice
     *
     * @param \furkankadioglu\eFatura\Models\Invoice|null $invoice
     * @param string                                      $reason
     *
     * @return boolean
     */
    public function cancelInvoice(Invoice $invoice = null, string $reason = "Yanlış İşlem")
    {
        if ($invoice != null) {
            $this->invoice = $invoice;
        }

        if ($this->invoice == null) {
            throw new NullDataException("Invoice variable not exist");
        }

        $data = [
            "silinecekler" => [$this->invoice->getSummary()],
            "aciklama" => $reason,
        ];


        $parameters = [
            "cmd" => "EARSIV_PORTAL_FATURA_SIL",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_BASITTASLAKLAR",
            "token" => $this->token,
            "jp" => "" . json_encode($data) . "",
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);
        $this->checkError($body);

        if (strpos($body["data"], " fatura başarıyla silindi.") == false) {
            throw new ApiException("Fatura iptal edilemedi.", 0, null, $body);
        }

        return true;
    }

    /**
     * Get an invoice from API
     *
     * @param \furkankadioglu\eFatura\Models\Invoice|null $invoice
     *
     * @return array
     */
    public function getInvoiceFromAPI(Invoice $invoice = null)
    {
        if ($invoice != null) {
            $this->invoice = $invoice;
        }

        if ($this->invoice == null) {
            throw new NullDataException("Invoice variable not exist");
        }

        $data = [
            "ettn" => $this->invoice->getUuid(),
        ];

        $parameters = [
            "cmd" => "EARSIV_PORTAL_FATURA_GETIR",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_BASITFATURA",
            "token" => $this->token,
            "jp" => "" . json_encode($data) . "",
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);

        $this->checkError($body);

        return $body["data"];
    }

    /**
     * Get download url
     *
     * @param \furkankadioglu\eFatura\Models\Invoice|null $invoice
     * @param boolean                                     $signed
     *
     * @return string
     */
    public function getDownloadURL(Invoice $invoice = null, bool $signed = true)
    {
        if ($invoice != null) {
            $this->invoice = $invoice;
        }

        if ($this->invoice == null) {
            throw new NullDataException("Invoice variable not exist");
        }

        $signed = $signed ? "Onaylandı" : "Onaylanmadı";

        return $this->getBaseUrl() . "/earsiv-services/download?token={$this->token}&ettn={$this->invoice->getUuid()}&belgeTip=FATURA&onayDurumu={$signed}&cmd=EARSIV_PORTAL_BELGE_INDIR";
    }

    /**
     * Set invoice manager user informations
     *
     * @param \furkankadioglu\eFatura\Models\UserInformations $userInformations
     *
     * @return self
     */
    public function setUserInformations(UserInformations $userInformations)
    {
        $this->userInformations = $userInformations;

        return $this;
    }

    /**
     * Get invoice manager user informations
     */
    public function getUserInformations()
    {
        return $this->userInformations;
    }

    /**
     * Get user informations data
     *
     * @return UserInformations
     */
    public function getUserInformationsData()
    {
        $parameters = [
            "cmd" => "EARSIV_PORTAL_KULLANICI_BILGILERI_GETIR",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_KULLANICI",
            "token" => $this->token,
            "jp" => "{}",
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);
        $this->checkError($body);

        $userInformations = new UserInformations($body["data"]);

        return $this->userInformations = $userInformations;
    }

    /**
     * Get Invoices from API
     *
     * @param string $startDate
     * @param string $endDate
     * @param array  $ettn
     *
     * @return array
     */
    public function getEttnInvoiceFromAPIArray(string $startDate, string $endDate, array $ettn)
    {
        $parameters = [
            "cmd" => "EARSIV_PORTAL_TASLAKLARI_GETIR",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_BASITTASLAKLAR",
            "token" => $this->token,
            "jp" => '{"baslangic":"' . $startDate . '","bitis":"' . $endDate . '","hangiTip":"5000/30000", "table":[]}',
        ];
        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);
        $this->checkError($body);
        $data = $body['data'];
        $dataFiltered = [];
        foreach ($data as $item) {
            if ($item["onayDurumu"] == "Onaylanmadı" and in_array($item["ettn"], $ettn)) {
                array_push($dataFiltered, $item);
            }
        }
        $this->invoices = $dataFiltered;

        return $dataFiltered;
    }

    /**
     * Send user informations data
     *
     * @param UserInformations|null $userInformations
     *
     * @return array
     */
    public function sendUserInformationsData(UserInformations $userInformations = null)
    {
        if ($userInformations != null) {
            $this->userInformations = $userInformations;
        }

        if ($this->userInformations == null) {
            throw new NullDataException("User informations data not exist");
        }

        $parameters = [
            "cmd" => "EARSIV_PORTAL_KULLANICI_BILGILERI_KAYDET",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_KULLANICI",
            "token" => $this->token,
            "jp" => "" . json_encode($this->userInformations->export()) . "",
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);
        $this->checkError($body);

        return $body["data"];
    }

    /**
     * Initialize SMS Verification
     *
     * @return boolean
     */
    private function initializeSMSVerification()
    {
        $parameters = [
            "cmd" => "EARSIV_PORTAL_TELEFONNO_SORGULA",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_BASITTASLAKLAR",
            "token" => $this->token,
            "jp" => "{}",
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);
        $this->checkError($body);

        if (! isset($body["data"]["telefon"])) {
            return false;
        }

        return true;
    }


    /**
     * Send user informations data
     *
     * @param string $phoneNumber
     *
     * @return array
     */
    public function sendSMSVerification(string $phoneNumber)
    {
        $this->initializeSMSVerification();

        $data = [
            "CEPTEL" => $phoneNumber,
            "KCEPTEL" => false,
            "TIP" => "",
        ];

        $parameters = [
            "cmd" => "EARSIV_PORTAL_SMSSIFRE_GONDER",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_SMSONAY",
            "token" => $this->token,
            "jp" => "" . json_encode($data) . "",
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);
        $this->checkError($body);

        $this->oid = $body["data"]["oid"];

        return $this->oid;
    }

    /**
     * Send user informations data
     *
     * @param $code
     * @param $operationId
     *
     * @return bool
     */
    public function verifySMSCode($code, $operationId)
    {
        $data = [
            "SIFRE" => $code,
            "OID" => $operationId,
            'OPR' => 1,
            'DATA' => $this->invoices,
        ];

        $parameters = [
            "cmd" => "0lhozfib5410mp",
            "callid" => Uuid::uuid1()->toString(),
            "pageName" => "RG_SMSONAY",
            "token" => $this->token,
            "jp" => "" . json_encode($data) . "",
        ];

        $body = $this->sendRequestAndGetBody(self::DISPATCH_PATH, $parameters);
        $this->checkError($body);

        if (! isset($body["data"]["sonuc"])) {
            return false;
        }

        if ($body["data"]["sonuc"] == 0) {
            return false;
        }

        return true;
    }
}
