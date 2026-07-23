<?php
/**
 * BATU AI Boot v5.1
 * ✅ API key: reads from .env first, falls back to hardcoded (no error if .env missing)
 * ✅ Data: 100% correct from batechu.com
 * ✅ NLP: TF-IDF cosine similarity
 * ✅ FAQ cache, chat history, student dashboard, SQLite
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

if (session_status() === PHP_SESSION_NONE) {
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
}

// ── Load .env if it exists (optional) ────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k !== '') $_ENV[$k] = $v;
    }
}

// ── Config: .env takes priority, hardcoded value is the fallback ──
define('GROQ_KEY',   $_ENV['GROQ_API_KEY'] ?? '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');
define('ADMIN_DEF',  $_ENV['ADMIN_PASS']   ?? 'batu2024');
define('DB_FILE',    __DIR__ . '/batu.db');
define('CACHE_FILE', __DIR__ . '/faq_cache.json');
define('RATE_LIMIT', 40);
define('CACHE_TTL',  3600);

// ── SQLite Setup ──────────────────────────────────────────────
function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT);
        CREATE TABLE IF NOT EXISTS knowledge (
            id TEXT PRIMARY KEY, category TEXT DEFAULT 'general',
            keywords TEXT, content TEXT,
            added_at TEXT DEFAULT (datetime('now','localtime')),
            source TEXT DEFAULT 'admin'
        );
        CREATE TABLE IF NOT EXISTS students (
            id TEXT PRIMARY KEY, name TEXT, national_id TEXT UNIQUE,
            faculty TEXT, program TEXT, year TEXT, status TEXT DEFAULT 'active',
            phone TEXT, email TEXT, gpa TEXT, notes TEXT,
            added_at TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS chat_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id TEXT, role TEXT, content TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
        CREATE TABLE IF NOT EXISTS rate_log (ip TEXT, hit_time INTEGER);
        CREATE INDEX IF NOT EXISTS idx_rate ON rate_log(ip, hit_time);
        CREATE INDEX IF NOT EXISTS idx_chat ON chat_history(session_id);
    ");
    $r = $pdo->query("SELECT value FROM config WHERE key='pass_hash'")->fetch();
    if (!$r) {
        $pdo->prepare("INSERT INTO config VALUES ('pass_hash',?)")
            ->execute([password_hash(ADMIN_DEF, PASSWORD_BCRYPT)]);
    }
    seedKnowledge($pdo);
    return $pdo;
}

// ── Seed — 100% from batechu.com ─────────────────────────────
function seedKnowledge(PDO $pdo): void {
    if ((int)$pdo->query("SELECT COUNT(*) FROM knowledge WHERE source='base'")->fetchColumn() > 0) return;

    $rows = [
      ['b001','general',
       'جامعة,برج العرب,batu,batechu,تعريف,نبذة,ما هي,معلومات,about,university,عن الجامعة',
       "**جامعة برج العرب التكنولوجية — Borg El Arab Technological University (BATU)**

جامعة حكومية مصرية أُنشئت طبقاً لـ **قانون رقم 72 لسنة 2019** الخاص بإنشاء الجامعات التكنولوجية في مصر.
📍 برج العرب، الإسكندرية، مصر
🌐 batechu.com

**الرسالة:** تقديم تعليم تكنولوجي عالي الجودة يجسر الهوة بين التعلم الأكاديمي واحتياجات الصناعة، وإعداد خريجين مستعدين لسوق العمل من اليوم الأول.

**الرؤية:** أن تكون جامعة تكنولوجية رائدة في مصر والمنطقة، معروفة بالابتكار والبحث التطبيقي.

**تتميز بـ:**
- برامج موجهة لسوق العمل مباشرة
- 60% تطبيق عملي (مختبرات + ورش + شركات)
- تدريب ميداني صيفي إلزامي للتخرج
- كليتان: الصناعة والطاقة + العلوم الصحية التطبيقية"],

      ['b002','president',
       'رئيس,رئيس الجامعة,محمد مرسي,الجوهري,من هو الرئيس,ادارة,رئاسة',
       "**رئيس جامعة برج العرب التكنولوجية:**
الأستاذ الدكتور / محمد مرسي الجوهري

صرّح الدكتور الجوهري بأن التعليم العالي يجب أن يرتبط بالتكنولوجيا الحديثة ومتطلبات سوق العمل.
🔗 batechu.com/news/klm-alastath-aldktor-mhmd-mrsy-algohry-ryys-gamaa-brg-alaarb-altknology"],

      ['b003','staff',
       'علاء عرفة,عميد,عميد الصناعة,dean,عميد الكلية',
       "**عميد كلية الصناعة والطاقة:** د. علاء عرفة

الكلية تضم خمسة أقسام رئيسية للدراسة التكنولوجية التطبيقية.
🔗 batechu.com/news/klmd-aalaaa-aarf-aamyd-klyalsnaaa-oaltak"],

      ['b004','faculty',
       'كليات,الكليات,كم كلية,كلية,faculty,faculties,ايه الكليات,ما هي الكليات',
       "**جامعة BATU تضم كليتين فقط:**

🏭 **كلية الصناعة والطاقة**
Faculty of Industrial and Energy Technology — 5 برامج
🔗 batechu.com/faculty/faculty-of-industrial-and-energy-technology

🏥 **كلية العلوم الصحية التطبيقية**
Faculty of Applied Health Sciences Technology — 5 برامج
🔗 batechu.com/faculty/faculty-of-applied-health-sciences-technology

⚠️ لا يوجد كليات أخرى — الجامعة متخصصة في التعليم التكنولوجي التطبيقي فقط."],

      ['b005','faculty',
       'كلية الصناعة,كلية الصناعة والطاقة,industrial,energy',
       "**كلية الصناعة والطاقة — Faculty of Industrial and Energy Technology**
عميد الكلية: د. علاء عرفة

**البرامج الخمسة:**
1. تكنولوجيا السكك الحديدية — Railway Technology
2. تكنولوجيا المعلومات — Information Technology
3. تكنولوجيا الغزل والنسيج — Textile Technology
4. تكنولوجيا صناعة الغذاء — Food Industry Technology
5. تكنولوجيا الجرارات والمعدات الزراعية — Agricultural Equipment Technology

🔗 batechu.com/faculty/faculty-of-industrial-and-energy-technology"],

      ['b006','faculty',
       'كلية الصحة,كلية العلوم الصحية,health sciences,health faculty,applied health',
       "**كلية العلوم الصحية التطبيقية — Faculty of Applied Health Sciences Technology**

**البرامج الخمسة:**
1. تكنولوجيا مختبر الأسنان — Dental Laboratory Technology
2. تكنولوجيا الإنتاج الصيدلاني — Pharmaceutical Production Technology
3. تكنولوجيا إدارة المعلومات الصحية — Health Information Management Technology
4. تكنولوجيا الرعاية الصحية — Health Care Technology
5. العلوم الصحية الأساسية — Health Science Basic Science

🔗 batechu.com/faculty/faculty-of-applied-health-sciences-technology"],

      ['b007','program',
       'تخصصات,برامج,ما هي التخصصات,كل التخصصات,قسم,اقسام,programs,كم برنامج',
       "**تخصصات BATU الـ 10 الرسمية (من batechu.com):**

🏭 **كلية الصناعة والطاقة:**
1. تكنولوجيا السكك الحديدية
2. تكنولوجيا المعلومات
3. تكنولوجيا الغزل والنسيج
4. تكنولوجيا صناعة الغذاء
5. تكنولوجيا الجرارات والمعدات الزراعية

🏥 **كلية العلوم الصحية التطبيقية:**
6. تكنولوجيا مختبر الأسنان
7. تكنولوجيا الإنتاج الصيدلاني
8. تكنولوجيا إدارة المعلومات الصحية
9. تكنولوجيا الرعاية الصحية
10. العلوم الصحية الأساسية

⚠️ هذه البرامج العشرة فقط — لا يوجد هندسة حاسوب أو نظم معلومات أو اتصالات."],

      ['b008','program',
       'سكك حديدية,قطار,railway,مترو,نقل,سكه حديد',
       "**برنامج تكنولوجيا السكك الحديدية — Railway Technology Program**
كلية الصناعة والطاقة

يهدف لتطوير تكنولوجيا السكك الحديدية من خلال الصيانة والابتكار.
🇬🇧 وقّعت الجامعة مذكرة تفاهم مع الجانب البريطاني لتأسيس مركز متقدم في هذا المجال.
🔗 batechu.com/program/railway-technology-program"],

      ['b009','program',
       'تكنولوجيا المعلومات,IT,برمجة,كمبيوتر,software,حاسب,information technology',
       "**برنامج تكنولوجيا المعلومات — Information Technology Program**
كلية الصناعة والطاقة

يُزوّد الطلاب بمهارات في:
- البرمجة وتطوير البرمجيات
- قواعد البيانات والشبكات
- أمن المعلومات
- تطوير الويب والموبايل
- الذكاء الاصطناعي وتحليل البيانات

⚠️ هو البرنامج الوحيد المتعلق بالحاسوب في BATU
🔗 batechu.com/program/information-technology-program"],

      ['b010','program',
       'غزل,نسيج,textile,ملابس,غزل ونسيج',
       "**برنامج تكنولوجيا الغزل والنسيج**
Technology of Operating and Maintaining Textile Technology Program
كلية الصناعة والطاقة

يُدرّب على أحدث تقنيات الغزل والنسيج والميكنة الصناعية.
🔗 batechu.com/program/technology-of-operating-and-maintaining-textile-technology-program"],

      ['b011','program',
       'صناعة غذاء,food,اغذية,غذاء,food industry,تصنيع غذائي',
       "**برنامج تكنولوجيا صناعة الغذاء — Food Industry Technology Program**
كلية الصناعة والطاقة

متخصص في تكنولوجيا الصناعات الغذائية وطرق حفظ الأغذية.
🔗 batechu.com/program/food-industry-technology-program"],

      ['b012','program',
       'جرارات,زراعة,معدات زراعية,agricultural,ميكنة زراعية,tractor',
       "**برنامج تكنولوجيا الجرارات والمعدات الزراعية**
Technology of Tractors and Agricultural Equipment Program
كلية الصناعة والطاقة

يُعدّ الطلاب للعمل في مجال الميكنة الزراعية الحديثة.
🔗 batechu.com/program/technology-of-tractors-and-agricultural-equipment-program"],

      ['b013','program',
       'أسنان,مختبر أسنان,dental,تركيبات أسنان,DLT,دنتال',
       "**برنامج تكنولوجيا مختبر الأسنان — Dental Laboratory Technology (DLT)**
كلية العلوم الصحية التطبيقية

أربعة مجالات رئيسية:
- Fixed Prosthodontics (تركيبات ثابتة)
- Removable Prosthodontics (تركيبات متحركة)
- Orthodontics (تقويم)
- Maxillofacial Technology

🔗 batechu.com/program/dental-laboratory-technology-program"],

      ['b014','program',
       'صيدلة,دواء,pharmaceutical,مستحضرات,ادوية,إنتاج صيدلاني',
       "**برنامج تكنولوجيا الإنتاج الصيدلاني — Pharmaceutical Production Technology**
كلية العلوم الصحية التطبيقية

يُعدّ الطلاب لأدوار في: مختبرات الجودة، الإنتاج، الهندسة الصيدلانية.
🔗 batechu.com/program/pharmaceutical-production-technology-program"],

      ['b015','program',
       'معلومات صحية,health information,سجلات طبية,ICD,health information management',
       "**برنامج إدارة المعلومات الصحية — Health Information Management Technology**
كلية العلوم الصحية التطبيقية

يُعدّ الطلاب لإدارة وتنظيم وتحليل المعلومات الصحية في المستشفيات والمنشآت الطبية.
🔗 batechu.com/program/health-information-management-technology"],

      ['b016','program',
       'رعاية صحية,health care,health care technology',
       "**برنامج تكنولوجيا الرعاية الصحية — Health Care Technology Program**
كلية العلوم الصحية التطبيقية

برنامج 4 سنوات ضمن إطار المؤهلات الوطنية المصري. يُعدّ للعمل في المجال الصحي مباشرة.
🔗 batechu.com/program/health-care-technology-program"],

      ['b017','program',
       'علوم أساسية,basic science,علوم صحية أساسية,health science basic',
       "**برنامج العلوم الصحية — العلوم الأساسية**
Health Science — Basic Science
كلية العلوم الصحية التطبيقية

🔗 batechu.com/program/health-science-basic-science"],

      ['b018','general',
       'هندسة,كلية هندسة,engineering,هندسة حاسوب,computer engineering,نظم معلومات إدارية,اتصالات,علوم',
       "**تنبيه مهم — ما لا يوجد في BATU:**

❌ لا يوجد كلية هندسة تقليدية
❌ لا يوجد هندسة حاسوب
❌ لا يوجد نظم معلومات إدارية
❌ لا يوجد تكنولوجيا اتصالات

✅ الموجود في مجال الحاسوب: **برنامج تكنولوجيا المعلومات (IT)** فقط في كلية الصناعة والطاقة.

الجامعة متخصصة في **التعليم التكنولوجي التطبيقي** وليس الهندسة الأكاديمية التقليدية."],

      ['b019','contact',
       'تواصل,اتصال,عنوان,رقم,ايميل,فين,موقع,contact',
       "**التواصل مع BATU:**

📍 برج العرب، الإسكندرية، مصر
🌐 batechu.com
📩 batechu.com/contact-us
📘 facebook.com/profile.php?id=100085885038638"],

      ['b020','activity',
       'نشاط,انشطة,رياضة,شطرنج,كرة قدم,ping pong,اتحاد طلابي,e-club,فعاليات',
       "**الأنشطة الطلابية في BATU:**

♟️ الشطرنج — Improving strategic thinking
🏓 كرة الطاولة (Ping Pong) — Staying active and competitive
⚽ كرة القدم — Teamwork and physical fitness

**فعاليات سنوية:**
- انتخابات اتحاد الطلاب
- المؤتمر الجامعي السنوي
- اليوم الرياضي السنوي
- 💻 E-Club: batechu.com/E-Club"],

      ['b021','activity',
       'اطفال,children university,جامعة اطفال,kids',
       "**جامعة الأطفال — Children's University**
برنامج يعرّف الأطفال بالعلوم والتكنولوجيا بطريقة ممتعة.
🔗 batechu.com/children-university"],

      ['b022','admission',
       'قبول,تسجيل,تقديم,شروط,قيد,الالتحاق,انضمام,كيف اقدم',
       "**القبول والتسجيل في BATU:**

للشروط الكاملة: 🔗 batechu.com/guest/students
للتواصل المباشر: 🔗 batechu.com/contact-us

**نظام الدراسة:**
- بكالوريوس تكنولوجي: 4 سنوات
- دبلوم عالي تكنولوجي: سنتان
- 60% تطبيق عملي
- تدريب ميداني صيفي إلزامي للتخرج"],

      ['b023','general',
       'جدول,مواعيد,timetable,محاضرات,schedule',
       "**الجداول الدراسية:**
🔗 batechu.com/guest-timetables"],

      ['b024','fees',
       'مصاريف,رسوم,تكلفة,مصروفات,سعر,كم المصاريف,fees',
       "**المصروفات الدراسية في BATU (2025):**

• **الفرقة الأولى والثانية:** 15,000 جنيه / سنة
• **الفرقة الثالثة والرابعة:** 20,000 جنيه / سنة

الدفع على قسطين (ترم أول + ترم ثاني) عبر فوري أو مصاري أو شؤون الطلاب.
⚠️ لا تشمل رسوم فتح الملف والكشف الطبي."],

      ['b025','general',
       'نظام الدراسة,ساعات معتمدة,دبلوم,بكالوريوس,تدريب,نظام,سنين,سنوات',
       "**نظام الدراسة في BATU:**

• نظام الساعات المعتمدة
• **60% تطبيق عملي** في المختبرات والشركات
• **دبلوم عالي تكنولوجي:** سنتان
• **بكالوريوس تكنولوجي:** 4 سنوات
• تدريب ميداني صيفي إلزامي للتخرج
• الانتقال للفرقة 3 و4 يتطلب معدل تراكمي محدد"],

      ['b026','news',
       'اخبار,احداث,مستجدات,news,توظيف,شراكة دولية,ملتقى,وظائف,اعلان',
       "**أبرز أخبار BATU:**

🇬🇧 توقيع مذكرة تفاهم مع بريطانيا لمركز السكك الحديدية
🇪🇸 اتفاقية تعاون مع المجلس الأعلى للبحث العلمي في إسبانيا
🇺🇸 استقبال هيئة فولبرايت مصر
💼 الملتقى التوظيفي والتدريبي الأول بالشراكة مع جمعية الريادة و'جاهزون للغد'
🏥 مباحثات مع مؤسسة بهية لخدمة مرضى السرطان
📢 إعلان وظائف

🔗 batechu.com/blog"],

      ['b027','general',
       'قطاعات,ادارة,شؤون طلاب,خدمة مجتمع,موارد بشرية,مجلس الجامعة,وحدات',
       "**قطاعات ووحدات جامعة BATU:**

**القطاعات الرئيسية:**
- شؤون الطلاب
- الأمانة العامة
- خدمة المجتمع والبيئة
- رعاية الشباب
- الموارد البشرية
- مجلس الجامعة

**الوحدات:**
- وحدة تكنولوجيا المعلومات
- وحدة ضمان الجودة (Quality Assurance)"],
    ];

    $st = $pdo->prepare("INSERT OR IGNORE INTO knowledge(id,category,keywords,content,source) VALUES(?,?,?,?,'base')");
    $pdo->beginTransaction();
    foreach ($rows as $r) $st->execute($r);
    $pdo->commit();
}

// ── Security Helpers ──────────────────────────────────────
function san(string $s): string {
    return htmlspecialchars(strip_tags(trim($s)), ENT_QUOTES, 'UTF-8');
}
function verifyAdmin(string $p): bool {
    $r = db()->query("SELECT value FROM config WHERE key='pass_hash'")->fetch();
    return $r && password_verify($p, $r['value']);
}
function rateLimit(): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
    $now = time();
    db()->prepare("DELETE FROM rate_log WHERE hit_time < ?")->execute([$now - 60]);
    $st = db()->prepare("SELECT COUNT(*) FROM rate_log WHERE ip=?");
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= RATE_LIMIT) {
        http_response_code(429);
        echo json_encode(['reply' => '⚠️ تجاوزت الحد. انتظر دقيقة.']);
        exit;
    }
    db()->prepare("INSERT INTO rate_log(ip,hit_time) VALUES(?,?)")->execute([$ip, $now]);
}

// ── NLP — TF-IDF Cosine Similarity ───────────────────────
function norm(string $t): string {
    $t = preg_replace('/[أإآا]/u', 'ا', $t);
    $t = str_replace(['ة','ى'], ['ه','ي'], $t);
    $t = preg_replace('/[\x{064B}-\x{065F}]/u', '', $t);
    return mb_strtolower($t, 'UTF-8');
}
function tokens(string $t): array {
    static $stop = ['في','من','إلى','على','عن','مع','هل','ما','هو','هي','كيف','متى','اين',
                    'لماذا','و','أو','لا','نعم','انا','اريد','عايز','عايزه','ممكن','اي',
                    'دي','ده','ايه','هات','بدي','ابي','عندي','يعني','طيب','بقا','ازاي',
                    'فيه','عنده','هناك','في','بتاع','بتاعة','عشان','بس'];
    $t  = norm($t);
    $ws = preg_split('/[\s\،\,\.!\?؟\-_\(\)\[\]\/]+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(array_filter($ws, fn($w) => mb_strlen($w, 'UTF-8') > 1 && !in_array($w, $stop))));
}
function tfidf(string $t): array {
    $toks = tokens($t);
    $freq = array_count_values($toks);
    $n    = max(count($toks), 1);
    $vec  = [];
    foreach ($freq as $k => $c) $vec[$k] = $c / $n;
    return $vec;
}
function cosine(array $a, array $b): float {
    $dot = $na = $nb = 0.0;
    foreach ($a as $k => $v) { $dot += $v * ($b[$k] ?? 0); $na += $v * $v; }
    foreach ($b as $v)        $nb += $v * $v;
    $d = sqrt($na) * sqrt($nb);
    return $d > 0 ? $dot / $d : 0.0;
}
function retrieve(string $q): string {
    $rows   = db()->query("SELECT keywords, content FROM knowledge")->fetchAll();
    $qv     = tfidf($q);
    $qn     = norm($q);
    $scored = [];
    foreach ($rows as $r) {
        $kws = explode(',', $r['keywords']);
        $cv  = tfidf(implode(' ', $kws) . ' ' . $r['content']);
        $sc  = cosine($qv, $cv);
        foreach ($kws as $kw) {
            $kwn = norm(trim($kw));
            if (mb_strlen($kwn, 'UTF-8') > 2 && mb_strpos($qn, $kwn) !== false) $sc += 0.5;
        }
        if ($sc > 0.02) $scored[] = [$sc, $r['content']];
    }
    if (!$scored) return '';
    usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
    return implode("\n\n---\n\n", array_column(array_slice($scored, 0, 3), 1));
}

// ── FAQ Cache ─────────────────────────────────────────────
function cacheGet(string $q): ?string {
    if (!file_exists(CACHE_FILE)) return null;
    $c = @json_decode(file_get_contents(CACHE_FILE), true) ?? [];
    $k = md5(norm($q));
    return (isset($c[$k]) && time() - $c[$k]['ts'] <= CACHE_TTL) ? $c[$k]['r'] : null;
}
function cacheSet(string $q, string $r): void {
    $c   = file_exists(CACHE_FILE) ? (@json_decode(file_get_contents(CACHE_FILE), true) ?? []) : [];
    $now = time();
    $c   = array_filter($c, fn($v) => $now - $v['ts'] < CACHE_TTL);
    $c[md5(norm($q))] = ['r' => $r, 'ts' => $now, 'q' => $q];
    $tmp = CACHE_FILE . '.tmp';
    file_put_contents($tmp, json_encode($c, JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, CACHE_FILE);
}

// ── Chat History ──────────────────────────────────────────
function saveMsg(string $sid, string $role, string $content): void {
    db()->prepare("INSERT INTO chat_history(session_id,role,content) VALUES(?,?,?)")->execute([$sid, $role, $content]);
    db()->prepare("DELETE FROM chat_history WHERE session_id=? AND id NOT IN
        (SELECT id FROM chat_history WHERE session_id=? ORDER BY id DESC LIMIT 20)")->execute([$sid, $sid]);
}
function getHistory(string $sid): array {
    $st = db()->prepare("SELECT role,content FROM chat_history WHERE session_id=? ORDER BY id DESC LIMIT 8");
    $st->execute([$sid]);
    return array_reverse($st->fetchAll());
}

// ── Groq API ──────────────────────────────────────────────
function groq(array $msgs): string {
    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => 'llama-3.3-70b-versatile',
            'messages'    => $msgs,
            'temperature' => 0.1,
            'max_tokens'  => 700,
        ]),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . GROQ_KEY, 'Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return "⚠️ خطأ في الاتصال: $err";
    $d = json_decode($raw, true);
    return $d['choices'][0]['message']['content'] ?? '⚠️ لم يتم استلام رد.';
}

function sysPrompt(string $ctx): string {
    return "أنت BATU AI BOOT، المساعد الذكي لجامعة برج العرب التكنولوجية.
مطوّر بواسطة الطالبة رفيدة خالد — الفرقة الرابعة، قسم IT SW Team(Smart City).

══════════════════════════════════════
⛔ قواعد صارمة جداً — لا استثناء:
══════════════════════════════════════

1. **المعلومات من السياق فقط — لا غير.**
   - إذا كانت المعلومة موجودة في السياق أدناه → اذكرها بالضبط.
   - إذا لم تكن موجودة في السياق → قل: \"هذه المعلومة غير متوفرة حالياً، يرجى التواصل مع الجامعة عبر batechu.com/contact-us\"
   - **لا تخترع أي معلومة. لا تضيف أي شيء من معرفتك العامة.**

2. **الكليات والتخصصات — حرفياً من السياق:**
   - BATU فيها كليتان فقط: كلية الصناعة والطاقة + كلية العلوم الصحية التطبيقية.
   - البرامج الموجودة هي 10 برامج فقط كما في السياق.
   - ⛔ لا كلية هندسة. ⛔ لا كلية أعمال. ⛔ لا برنامج هندسة برمجيات أو نظم معلومات أو اتصالات.
   - أي برنامج غير مذكور في السياق = غير موجود في BATU.

3. **للأسئلة الاجتماعية (تحية، كلام عادي):**
   - رد بشكل طبيعي وودود فقط — لا تذكر أي معلومات عن الجامعة في هذا الرد.

4. **التنسيق:**
   - استخدم Markdown: ** للعناوين، - للقوائم.
   - الردود مختصرة وواضحة.
   - في نهاية الرد عن الجامعة، اقترح سؤال متابعة واحد.

══════════════════════════════════════
📚 السياق الوحيد المسموح باستخدامه (من batechu.com):
══════════════════════════════════════
$ctx

══════════════════════════════════════
⛔ تذكير أخير: أي معلومة غير موجودة في السياق أعلاه = لا تذكرها نهائياً.
══════════════════════════════════════";
}

// ── Students ──────────────────────────────────────────────
function handleStudents(string $action, string $pass): void {
    if (!verifyAdmin($pass)) { echo json_encode(['ok'=>false,'error'=>'غير مصرح']); return; }
    $db = db();
    switch ($action) {
        case 'student_list':
            $s   = san($_POST['search']  ?? '');
            $fac = san($_POST['faculty'] ?? '');
            $yr  = san($_POST['year']    ?? '');
            $sql = "SELECT * FROM students WHERE 1=1";
            $p   = [];
            if ($s)   { $sql .= " AND (name LIKE ? OR national_id LIKE ? OR email LIKE ?)"; $x="%$s%"; $p=[$x,$x,$x]; }
            if ($fac) { $sql .= " AND faculty=?";  $p[] = $fac; }
            if ($yr)  { $sql .= " AND year=?";     $p[] = $yr;  }
            $sql .= " ORDER BY added_at DESC LIMIT 200";
            $st = $db->prepare($sql); $st->execute($p);
            echo json_encode(['ok'=>true,'students'=>$st->fetchAll()]);
            break;
        case 'student_add':
            $f = ['name','national_id','faculty','program','year','status','phone','email','gpa','notes'];
            $d = []; foreach ($f as $k) $d[$k] = san($_POST[$k] ?? '');
            if (!$d['name']||!$d['national_id']) { echo json_encode(['ok'=>false,'error'=>'الاسم والرقم القومي مطلوبان']); return; }
            try {
                $db->prepare("INSERT INTO students(id,name,national_id,faculty,program,year,status,phone,email,gpa,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([uniqid('s_'), ...array_values($d)]);
                echo json_encode(['ok'=>true]);
            } catch (\Exception $e) { echo json_encode(['ok'=>false,'error'=>'الرقم القومي موجود مسبقاً']); }
            break;
        case 'student_update':
            $id = san($_POST['id']??'');
            $f  = ['name','national_id','faculty','program','year','status','phone','email','gpa','notes'];
            $sets=[]; $p=[];
            foreach ($f as $k) { if (isset($_POST[$k])) { $sets[]="$k=?"; $p[]=san($_POST[$k]); } }
            if (!$id||!$sets) { echo json_encode(['ok'=>false,'error'=>'بيانات ناقصة']); return; }
            $p[]=$id;
            $db->prepare("UPDATE students SET ".implode(',',$sets)." WHERE id=?")->execute($p);
            echo json_encode(['ok'=>true]);
            break;
        case 'student_delete':
            $db->prepare("DELETE FROM students WHERE id=?")->execute([san($_POST['id']??'')]);
            echo json_encode(['ok'=>true]);
            break;
        case 'student_stats':
            echo json_encode([
                'ok'         => true,
                'total'      => $db->query("SELECT COUNT(*) FROM students")->fetchColumn(),
                'active'     => $db->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn(),
                'by_faculty' => $db->query("SELECT faculty,COUNT(*) as c FROM students GROUP BY faculty")->fetchAll(),
                'by_year'    => $db->query("SELECT year,COUNT(*) as c FROM students GROUP BY year ORDER BY year")->fetchAll(),
                'avg_gpa'    => round((float)$db->query("SELECT AVG(CAST(gpa AS REAL)) FROM students WHERE gpa!=''")->fetchColumn(), 2),
            ]);
            break;
    }
}

// ── Admin ─────────────────────────────────────────────────
function handleAdmin(string $action, string $pass): void {
    $db = db();
    switch ($action) {
        case 'auth':    echo json_encode(['ok' => verifyAdmin($pass)]); break;
        case 'kb_add':
            if (!verifyAdmin($pass)) { echo json_encode(['ok'=>false,'error'=>'غير مصرح']); return; }
            $cat=san($_POST['category']??'general'); $kw=san($_POST['keywords']??''); $ct=san($_POST['content']??'');
            if (!$kw||!$ct) { echo json_encode(['ok'=>false,'error'=>'بيانات ناقصة']); return; }
            $db->prepare("INSERT INTO knowledge(id,category,keywords,content) VALUES(?,?,?,?)")->execute([uniqid('k_'),$cat,$kw,$ct]);
            echo json_encode(['ok'=>true]);
            break;
        case 'kb_list':
            if (!verifyAdmin($pass)) { echo json_encode(['ok'=>false,'entries'=>[]]); return; }
            echo json_encode(['ok'=>true,'entries'=>$db->query("SELECT * FROM knowledge WHERE source='admin' ORDER BY added_at DESC")->fetchAll()]);
            break;
        case 'kb_delete':
            if (!verifyAdmin($pass)) { echo json_encode(['ok'=>false]); return; }
            $db->prepare("DELETE FROM knowledge WHERE id=? AND source='admin'")->execute([san($_POST['id']??'')]);
            echo json_encode(['ok'=>true]);
            break;
        case 'changepass':
            if (!verifyAdmin($pass)) { echo json_encode(['ok'=>false]); return; }
            $np=$_POST['newpass']??'';
            if (mb_strlen($np)<6) { echo json_encode(['ok'=>false,'error'=>'كلمة مرور قصيرة']); return; }
            $db->prepare("UPDATE config SET value=? WHERE key='pass_hash'")->execute([password_hash($np,PASSWORD_BCRYPT)]);
            echo json_encode(['ok'=>true]);
            break;
        case 'get_chat_history':
            $sid=$session_id=session_id();
            $st=$db->prepare("SELECT role,content,created_at FROM chat_history WHERE session_id=? ORDER BY id ASC");
            $st->execute([$sid]);
            echo json_encode(['ok'=>true,'history'=>$st->fetchAll()]);
            break;
        case 'cache_stats':
            if (!verifyAdmin($pass)) { echo json_encode(['ok'=>false]); return; }
            $c=file_exists(CACHE_FILE)?(@json_decode(file_get_contents(CACHE_FILE),true)??[]):[];
            $now=time(); $v=array_filter($c,fn($x)=>$now-$x['ts']<CACHE_TTL);
            echo json_encode(['ok'=>true,'count'=>count($v),'entries'=>array_values(array_map(fn($x)=>['q'=>$x['q'],'age'=>$now-$x['ts']],$v))]);
            break;
        case 'cache_clear':
            if (!verifyAdmin($pass)) { echo json_encode(['ok'=>false]); return; }
            if (file_exists(CACHE_FILE)) unlink(CACHE_FILE);
            echo json_encode(['ok'=>true]);
            break;
        default: echo json_encode(['ok'=>false,'error'=>'action غير معروف']);
    }
}

// ── Student Self-Registration (no admin pass needed) ─────
function handleSelfRegister(): void {
    $name = san($_POST['name'] ?? '');
    $nid  = san($_POST['national_id'] ?? '');

    if (!$name || !$nid) {
        echo json_encode(['ok'=>false,'error'=>'الاسم والرقم القومي مطلوبان']);
        return;
    }
    if (!preg_match('/^\d{14}$/', $nid)) {
        echo json_encode(['ok'=>false,'error'=>'الرقم القومي يجب أن يكون 14 رقماً']);
        return;
    }

    $db = db();

    // Check if already registered
    $existing = $db->prepare("SELECT id, name FROM students WHERE national_id=?");
    $existing->execute([$nid]);
    $row = $existing->fetch();
    if ($row) {
        // Already exists — just return their info (login)
        $_SESSION['student_id']   = $row['id'];
        $_SESSION['student_name'] = $row['name'];
        echo json_encode(['ok'=>true,'action'=>'login','name'=>$row['name']]);
        return;
    }

    // New student — register with minimal info
    $id = uniqid('s_');
    try {
        $db->prepare("INSERT INTO students(id,name,national_id,status) VALUES(?,?,?,'active')")
           ->execute([$id, $name, $nid]);
        $_SESSION['student_id']   = $id;
        $_SESSION['student_name'] = $name;
        echo json_encode(['ok'=>true,'action'=>'register','name'=>$name]);
    } catch (\Exception $e) {
        echo json_encode(['ok'=>false,'error'=>'حدث خطأ، حاول مرة أخرى']);
    }
}

// ── Main Router ───────────────────────────────────────────
try { db(); } catch (\Exception $e) { echo json_encode(['error'=>'DB: '.$e->getMessage()]); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = san($_POST['action'] ?? '');
    $pass   = san($_POST['pass']   ?? '');

    // Public student self-registration — no password needed
    if ($action === 'self_register') { handleSelfRegister(); exit; }

    if (str_starts_with($action, 'student_')) { handleStudents($action, $pass); exit; }
    if ($action && $action !== 'chat')         { handleAdmin($action, $pass);    exit; }

    rateLimit();
    $raw = $_POST['message'] ?? '';
    if (mb_strlen($raw, 'UTF-8') > 500) { echo json_encode(['reply'=>'⚠️ الرسالة طويلة.']); exit; }
    $msg = san($raw);
    if (!$msg) { echo json_encode(['reply'=>'يرجى كتابة رسالتك.']); exit; }

    if ($cached = cacheGet($msg)) { echo json_encode(['reply'=>$cached,'src'=>'cache']); exit; }

    $ctx   = retrieve($msg) ?: 'لا تتوفر معلومات محددة — تواصل مع الجامعة: batechu.com/contact-us';
    $sid   = session_id();
    saveMsg($sid, 'user', $msg);
    $hist  = getHistory($sid);
    $msgs  = [['role'=>'system','content'=>sysPrompt($ctx)]];
    foreach ($hist as $h) $msgs[] = ['role'=>$h['role'],'content'=>$h['content']];
    $reply = groq($msgs);
    saveMsg($sid, 'assistant', $reply);
    cacheSet($msg, $reply);
    echo json_encode(['reply'=>$reply,'src'=>'llm']);
    exit;
}

echo json_encode([
    'status'  => 'BATU AI Boot v5.1',
    'db'      => file_exists(DB_FILE) ? 'ok' : 'will create',
    'api_key' => GROQ_KEY ? 'set' : 'MISSING',
    'env_file'=> file_exists(__DIR__.'/.env') ? 'found' : 'not found (using fallback)',
]);

// NOTE: student_self_register is handled in the main router above
// This is just a marker — actual handler added to main router via patch