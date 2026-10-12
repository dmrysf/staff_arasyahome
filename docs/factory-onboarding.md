# Fabrika çalışanları: hazırlık, manuel kurulum ve ayrı grup aktivasyonu

Bu sürüm yalnız hazırlıktır. Codex deploy, migration, seed, hesap aktivasyonu veya parola sıfırlaması yapmaz.
Mal sahibi manuel API deploy'u tamamladıktan sonra **yalnız Kesim 4 kişi için ayrıca açık onay** verir.
Diğer 25 kişi, başarılı pilotun ardından ayrı grup onaylarıyla ilerler. Hazırlık/deploy onayı aktivasyon onayı değildir.

## Sabit kapsam

| CLI grubu | Kişi | Aşama | Kaynak |
|---|---:|---|---|
| `cutting` | 4 | 2: `material-preparation` | trendhome, outletperdele, trendyol |
| `workshop` | 15 | 3–12: workshop-receiving → quality-control | aynı üç kaynak |
| `packing` | 4 | 13: `packing` | aynı üç kaynak |
| `internal-handoff` | 6 | 14: `delivery` | aynı üç kaynak |

Kişiler [sabit matriste](../operations-api/database/reference/factory-onboarding.json) kayıtlıdır; CLI dışarıdan kişi/yetki matrisi kabul etmez.
Mevcut 29 IAM kimliği kullanılır; yeni kimlik, rol, departman veya iş akışı oluşturulmaz.
Sadece Staff uygulamasının mevcut 10 temel izni verilir. Dashboard, B2B, Trendyol sipariş onay rolü,
belge üretim/onay rolü, belge kaynak kapsamı ve `waiting` aşaması verilmez. Almanya çalışanları, Ayşe ve diğer çalışanlar kapsam dışıdır.
14 aşamalı kanonik akış, siparişler, QR/PDF kodu, departmanlar ve yöneticiler değiştirilmez.

## 1. Yedek ve restore kapısı

Migration 023 sonrası tutarlı `--single-transaction` yedeği, sunucuda özel `~/arasya-backups` (0700) altında 0600 dosya olarak tutulur.
Restore provasının başarılı olduğuna ilişkin tablo/satır, migration ve mantıksal içerik karşılaştırması raporu olmadan aktivasyona geçilmez.
Prova hedefi üretimden ayrı veritabanı/sunucu olmalı; canlı veritabanına restore yapılmamalı.
Tam SQL yedeği hiçbir GitHub branch'ine, rapora veya chat çıktısına eklenmez.
Deploy öncesi yeni siparişler oluştuysa yönetici yeni tutarlı yedek ve restore doğrulaması alır.

## 2. Manuel cPanel API kurulumu (yönetici)

1. PR'ı ve testleri inceleyin. İncelemeden sonra kaynak PR'ı kendiniz birleştirin.
2. `main` üzerindeki Operations API CI başarılı olsun. Başarılı yayın işi `api-deploy` paketini API **2.28.0** olarak üretir.
   PR CI yayın yapmaz. `release.json.sourceCommit` birleşen kaynak commit'i olmalıdır.
3. cPanel Git Version Control'de **Operations API** deposunu/`api-deploy` branch'ini seçin; *Update from Remote* yapın.
   Staff deposunu bu işlem için deploy etmeyin. Bu hazırlık yeni migration veya seed gerektirmez; production 001–023 zaten uygulanmıştır.
4. API paketinin cPanel checkout dizininde, Terminal'den yalnız doğrulama çalıştırın:

   ```bash
   /bin/bash ./scripts/validate-release.sh "$PWD"
   ARASYA_API_ROOT_PATH="$HOME/arasya-operations-api" ARASYA_API_PUBLIC_PATH="$HOME/api.arasyahome.ro" DRY_RUN=1 /bin/bash ./scripts/cpanel-deploy-api.sh
   ```

5. Sonuç doğruysa cPanel'de *Deploy HEAD Commit* işlemini **kendiniz** yapın. Terminal alternatifi aynı API paket dizininde:

   ```bash
   ARASYA_API_ROOT_PATH="$HOME/arasya-operations-api" ARASYA_API_PUBLIC_PATH="$HOME/api.arasyahome.ro" /bin/bash ./scripts/cpanel-deploy-api.sh
   ```

6. Etkin runtime'ı pointer üzerinden çözün; eski `current/` dizinini kullanmayın:

   ```bash
   api_sha="$(cat "$HOME/arasya-operations-api/active-release")"
   [[ "$api_sha" =~ ^[0-9a-f]{40}$ ]] || exit 1
   api_runtime="$HOME/arasya-operations-api/releases/$api_sha"
   /bin/bash "$api_runtime/scripts/validate-release.sh" "$api_runtime"
   /usr/local/bin/php "$api_runtime/bin/migration-status.php"
   curl --fail --silent --show-error https://api.arasyahome.ro/health
   /usr/local/bin/php "$api_runtime/bin/readiness.php"
   ```

   24 migration, readiness OK, API 2.28.0 ve doğru `sourceCommit` beklenir. Eksik migration varsa bu prosedürü durdurun;
   onboarding bahanesiyle `migrate.php`, `seed.php` veya `organization-provision.php --apply` çalıştırmayın.
   QR/PDF ve Ayşe'nin mevcut Trendyol işlemlerini mevcut hesaplarla kontrol edin; test için gerçek sipariş oluşturmayın/ilerletmeyin.

## 3. Kurulum sonrası salt okunur dry-run

```bash
umask 077
mkdir -p "$HOME/arasya-onboarding"
chmod 700 "$HOME/arasya-onboarding"
/usr/local/bin/php "$api_runtime/bin/factory-provision.php" --json > "$HOME/arasya-onboarding/factory-all-plan.json"
/usr/local/bin/php "$api_runtime/bin/factory-provision.php" --group=cutting --json > "$HOME/arasya-onboarding/factory-cutting-plan.json"
chmod 600 "$HOME/arasya-onboarding/factory-all-plan.json" "$HOME/arasya-onboarding/factory-cutting-plan.json"
```

Komut varsayılan olarak dry-run'dır; DB tarafından `READ ONLY` transaction uygulanır.
Tüm plan: requested/found/activate = 29, conflicts/newIdentities/productionWrites = 0.
Kesim planı: 4 kişi, yalnız material-preparation ve üç kaynak; conflicts = 0.
Her iki komut da sıfır exit code vermeli. Çıktı kimlik/yetki metadata bilgisi taşır; parola içermez, yine özel tutulur.
Dosyaları Git'e koymayın. Canlı durum değiştiğinde plan SHA değişir; deploy sonrası yeni plan sahibine gösterilir.

## 4. Ayrı Kesim onayı — şimdilik çalıştırmayın

Sahibin deploy sonrası dört kesim kişisi için açık aktivasyon onayı gelmeden onay dosyası hazırlamayın ve `--apply` çalıştırmayın.
Onay geldiğinde **yeni** kesim dry-run'ını inceleyin ve `planSha256` değerini kaydedin.
Operatör, sahibin onay referansı ve adını kaydeden aşağıdaki JSON'u özel dizinde oluşturur. Yer tutucular geçerli onay değildir:

```json
{
  "group": "cutting",
  "planSha256": "<incelenen güncel kesim planındaki 64 karakter SHA256>",
  "reference": "<sahibin ayrı aktivasyon onayının kayıt/tarih referansı>",
  "approvedBy": "<onaylayan mal sahibi>",
  "approvedAt": "<UTC ISO-8601 onay zamanı, örnek biçim YYYY-MM-DDTHH:MM:SS+00:00>",
  "expiresAt": "<onaydan en fazla 24 saat sonra, gelecekteki UTC ISO-8601 zaman>"
}
```

```bash
chmod 600 "$HOME/arasya-onboarding/approval-cutting.json"
factory_plan_sha='<incelenen kesim planının SHA256 değeri>'
/usr/local/bin/php "$api_runtime/bin/factory-provision.php" --apply --group=cutting --expected-plan="$factory_plan_sha" --approval-file="$HOME/arasya-onboarding/approval-cutting.json" --credentials-dir="$HOME/arasya-onboarding"
```

Onay dosyası dış web/kod dizinlerinden uzak, 0700 dizinde 0600 olmalı; symlink kabul edilmez.
Bu dosya kriptografik bir imza değildir: sahibin ayrı onayını operatörün kaydetmesini ve güncel plan/grup/zaman kapısını sağlar.
Üretimde bu komuta erişimi olan sunucu yöneticisi güven sınırıdır. Hazırlık sırasında geçerli onay dosyası oluşturulmaz.

Sistem root IAM kimliğiyle mevcut audited ManagementService işlemlerini kullanır. Grup için advisory lock ve sıralı çalışan row lock'ları alır;
kilit altında plan SHA'yı tekrar karşılaştırır. Her aşama, kaynaklarıyla birlikte tek IAM işlemiyle verilir; grubun tamamı tek transaction'dır.
Bir çalışanın parola dosyası yazılamazsa tüm grubun uygulama, aşama/kaynak, parola, durum ve audit değişiklikleri geri alınır.
Başarılı aktivasyonun durable audit işareti yeniden çalıştırmada yeni parola/yetki/durum işlemi yapılmasını engeller.
Sonradan elle devre dışı bırakılmış bir hesap otomatik tekrar aktifleştirilmez. Beklenmeyen mevcut yetki silinmez; işlem conflict ile durur.

## 5. İlk giriş ve pilot

- Gerçek geçici parolalar **yalnız bu onaylı apply sırasında** üretilir. Ön hazırlıkta parola yoktur.
- Mevcut `CredentialFile`, yalnız aktif grubun kişilerine ayrı sayfalar içeren `credentials-*.txt` üretir: dizin 0700, dosya 0600,
  web root ve runtime dışında. İçerik stdout, IAM audit, rapor, GitHub veya normal log'a yazılmaz.
- Yönetici sayfaları kişiye özel güvenli şekilde teslim eder. İlk girişte parola değişmeden operasyon API'si kapalıdır;
  değişiklikte eski oturum iptal edilir. Teslim sonrası özel dosya güvenli şekilde kaldırılır.
- Apply başarılıysa yalnız 4 kesim için activated=4, credentials=4; diğer 25 kişi inactive kalmalıdır.
  `--group=cutting` dry-run tekrarında unchanged=4, activate=0 ve conflict=0 olmalıdır; CredentialFile tekrar üretilmez.
- Hata halinde komut sıfırdan farklı döner. Başarısız transaction'da oluşturulan dosyayı teslim etmeyin; parolaları geçersizdir, o dosyayı silin.
  Mevcut başka bir çalıştırmanın başarılı credential dosyasını silmeyin. Bağlantı commit anında kesilmişse önce dry-run ve IAM audit ile
  commit durumunu doğrulayın; sonucu görmeden yeniden parola sıfırlamayın. Başarılı dosya kaybolursa yeniden parola üretimi için ayrıca sahip onayı gerekir.
- Sahip, kontrollü pilotu dört kişiyle yürütür. Kaynak/aşama sınırları, sahiplenme, kişisel audit ve ardışık akış doğrulanır.
  Pilot başarılı olmadan workshop/packing/internal-handoff apply çalıştırılmaz. Her sonraki grup yeni dry-run, yeni SHA ve ayrı onay dosyası ister.

## 6. Durdurma ve geri dönüş

Pilot sorununda diğer grupları durdurun. Yönetici mevcut audited IAM servisi/Dashboard üzerinden yalnız pilot kişilerini devre dışı bırakır;
çalışan/audit geçmişi veya sipariş silmez. Canlı DB'yi yedekten restore etmeyin; bu, yeni siparişleri kaybettirebilir.
Yetki geri dönüşünü kaydedilen `before` planına göre mevcut IAM işlemleriyle ayrı sahibi onayıyla yapın.
Bir sipariş hâlâ pilot çalışanın üzerindeyse mevcut yetkili yönetici sahipliği ayrı audited işlemle çözer; onboarding sipariş sahipliği değiştirmez.

Kod rollback'i yalnız manuel pointer değişikliğidir ve aktivasyonları otomatik geri almaz:

```bash
/bin/bash "$api_runtime/scripts/cpanel-rollback-api.sh" --previous
```

Restore/deploy/aktivasyon birbirinden ayrı işlemlerdir. Bu hazırlık çalışması hiçbirini otomatik zamanlamaz.

## Doğrulama

Yeni `mysql-factory-onboarding-integration.php` yalnız açıkça isimlendirilmiş sentetik test DB'sinde çalışır.
Dört grup/29 kişi için 14 aşama × dört kaynak server policy kontrolü, HTTP aşama kapıları, B2B retleri, scope atomikliği,
CredentialSink hata geri dönüşü, ayrı onay/SHA kapısı, idempotency, ilk giriş/oturum iptali, kişisel audit ve korunacak sentetik Ayşe/Almanya/B2B profilleri test edilir.
Mevcut IAM, stage-source-scopes, sipariş sahipliği ve QR/PDF regresyon testleri ayrıca çalıştırılır.
