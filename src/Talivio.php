<?php

namespace Talivio\Sdk;

class Talivio
{
    /**
     * Paket sürümü.
     *
     * Her ingest isteğinde `X-Talivio-Sdk-Version` başlığıyla hub'a gider.
     * NEDEN: ingest uçlarında sürüm öneki yok (`/api/ingest/*` sabit) ve hub
     * bugüne kadar bir ürünün hangi SDK sürümünü koştuğunu göremiyordu. Bir
     * alan adı değiştiğinde henüz güncellenmemiş ürünlerin telemetrisi sessizce
     * düşerdi; bu başlık o sapmayı görünür kılar.
     *
     * composer.json'da `version` alanı YOK (Composer'ın kendi uyarısı: etiketten
     * türetilmeli), bu yüzden sürüm burada elle tutulur — etiket atarken
     * güncellenmesi gerekir.
     */
    public const VERSION = '1.20.1';
}
