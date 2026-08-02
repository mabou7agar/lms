/**
 * Home V2 marketing content — brand/marketing copy for the premium homepage sections
 * (why, learning experience, journey, testimonials, instructor + enterprise proof).
 * Bilingual { en, ar }. This is brand marketing content (not course/API data); representative
 * testimonials/instructors are clearly brand-seeded and swappable from admin settings later.
 */
import type { Localized } from "./theme";

const L = (en: string, ar: string): Localized => ({ en, ar });

export const proofMetrics = [
  { value: "25,000+", label: L("Learners across MENA", "متعلّم في المنطقة") },
  { value: "100+", label: L("Courses & programs", "دورة وبرنامج") },
  { value: "75", label: L("Enterprise & government clients", "عميل مؤسسي وحكومي") },
  { value: "65%", label: L("Cohort completion rate", "معدل إتمام الأفواج") },
  { value: "4.8/5", label: L("Average learner rating", "متوسط تقييم المتعلّمين") },
  { value: "3", label: L("Regional hubs · Cairo · Dubai · Riyadh", "مراكز إقليمية · القاهرة · دبي · الرياض") },
];

export const whyHeading = {
  eyebrow: L("WHY HELBARON", "لماذا HElbaron"),
  title1: L("Built for outcomes,", "مبنيّة على النتائج،"),
  title2: L("not just content.", "لا المحتوى فقط."),
  subtitle: L(
    "Most platforms sell videos. HElbaron is engineered around the way MENA professionals actually build careers and companies.",
    "معظم المنصّات تبيع فيديوهات. HElbaron مصمّمة حول الطريقة التي يبني بها محترفو المنطقة مسيرتهم وشركاتهم فعلًا.",
  ),
};

export const whyPoints = [
  {
    icon: "Target",
    title: L("Outcome-first curriculum", "منهج يبدأ من النتيجة"),
    body: L("Every program maps to a concrete capability — a shipped project, a passed decision, a real deliverable.", "كل برنامج مرتبط بقدرة ملموسة — مشروع مُنفَّذ أو قرار صائب أو ناتج حقيقي."),
  },
  {
    icon: "Languages",
    title: L("Truly bilingual", "ثنائية اللغة فعلًا"),
    body: L("Arabic and English as first-class experiences — not a translation layer bolted on afterwards.", "العربية والإنجليزية تجربتان أصيلتان — لا طبقة ترجمة مُضافة لاحقًا."),
  },
  {
    icon: "Users",
    title: L("Practitioners, not lecturers", "ممارسون لا محاضرون"),
    body: L("Taught by operators who have built and scaled in the region — you learn what actually works here.", "يقودها ممارسون بنَوا وطوّروا في المنطقة — تتعلّم ما ينجح هنا فعلًا."),
  },
  {
    icon: "Route",
    title: L("One continuous journey", "رحلة واحدة متّصلة"),
    body: L("Course to cohort to enterprise academy to advisory — the ladder never breaks as you grow.", "من دورة إلى فوج إلى أكاديمية مؤسسية إلى استشارات — السلّم لا ينكسر مع نموّك."),
  },
];

export const experienceHeading = {
  eyebrow: L("THE LEARNING EXPERIENCE", "تجربة التعلّم"),
  title1: L("A real product,", "منتج حقيقي،"),
  title2: L("not a video library.", "لا مكتبة فيديو."),
  subtitle: L(
    "A focused player, structured curriculum, hands-on assignments, live sessions, verifiable certificates, and analytics — one coherent product.",
    "مشغّل مركّز ومنهج منظّم ومهام تطبيقية وجلسات مباشرة وشهادات قابلة للتحقّق وتحليلات — منتج واحد متكامل.",
  ),
};

export const experiencePanels = [
  { icon: "PlayCircle", title: L("Focused player", "مشغّل مركّز"), body: L("Distraction-free video with notes, transcripts, and resume-where-you-left-off.", "فيديو بلا تشتيت مع ملاحظات ونصوص واستئناف من حيث توقّفت.") },
  { icon: "ListChecks", title: L("Structured curriculum", "منهج منظّم"), body: L("Modules, lessons, and milestones that build toward a real capability.", "وحدات ودروس ومراحل تبني نحو قدرة حقيقية.") },
  { icon: "PenLine", title: L("Hands-on assignments", "مهام تطبيقية"), body: L("Submit work, get graded feedback, and iterate — not passive watching.", "سلّم عملك واحصل على تقييم وطوّر — لا مشاهدة سلبية.") },
  { icon: "Radio", title: L("Live cohorts", "أفواج مباشرة"), body: L("Weekly live sessions, mentors, and a peer group that keeps you moving.", "جلسات أسبوعية مباشرة ومرشدون ومجموعة أقران تدفعك للأمام.") },
  { icon: "BadgeCheck", title: L("Verifiable certificates", "شهادات موثّقة"), body: L("Credentials employers can verify by code — earned, not bought.", "شهادات يتحقّق منها أصحاب العمل بالرمز — تُكتسب لا تُشترى.") },
  { icon: "LineChart", title: L("Progress analytics", "تحليلات التقدّم"), body: L("Skills, streaks, and completion — clear signal for you and for L&D teams.", "المهارات والاستمرارية والإتمام — إشارة واضحة لك ولفرق التطوير.") },
];

export const journeyHeading = {
  eyebrow: L("THE JOURNEY", "الرحلة"),
  title1: L("Start with one course.", "ابدأ بدورة واحدة."),
  title2: L("Grow into an academy.", "وتوسّع إلى أكاديمية."),
  subtitle: L("The same platform grows with you — from a first skill to an organization-wide capability.", "المنصّة نفسها تنمو معك — من أول مهارة إلى قدرة على مستوى المؤسسة."),
};

export const journeySteps = [
  { step: "01", title: L("Learn a skill", "تعلّم مهارة"), body: L("Pick an on-demand course and ship your first real outcome.", "اختر دورة عند الطلب وحقّق أول ناتج حقيقي."), meta: L("Courses", "الدورات") },
  { step: "02", title: L("Join a cohort", "انضم إلى فوج"), body: L("Go deeper with a mentor-led, 8–12 week live program.", "تعمّق مع برنامج مباشر بإشراف مرشد من 8–12 أسبوعًا."), meta: L("Live Cohorts", "الأفواج") },
  { step: "03", title: L("Practice in person", "تدرّب حضوريًا"), body: L("Sharpen with hands-on workshops in Cairo, Dubai, Riyadh.", "اصقل مهاراتك بورش حضورية في القاهرة ودبي والرياض."), meta: L("Workshops", "الورش") },
  { step: "04", title: L("Upskill your org", "طوّر مؤسستك"), body: L("Roll out a custom academy with SSO, SCORM, and reporting.", "أطلق أكاديمية مخصّصة مع دخول موحّد وSCORM وتقارير."), meta: L("Enterprise", "المؤسسات") },
  { step: "05", title: L("Partner on strategy", "اعقد شراكة استراتيجية"), body: L("Bring in HElbaron Advisory to turn capability into growth.", "استعن باستشارات HElbaron لتحويل القدرة إلى نمو."), meta: L("Advisory", "الاستشارات") },
];

export const testimonialsHeading = {
  eyebrow: L("LOVED BY BUILDERS", "محبوبة من الصنّاع"),
  title1: L("Trusted by the people", "موثوقة من الأشخاص"),
  title2: L("building MENA business.", "الذين يبنون أعمال المنطقة."),
};

export const testimonials = [
  { quote: L("The cohort changed how our whole PMO operates. Practical, regional, and actually finished — rare for online learning.", "غيّر الفوج طريقة عمل مكتب إدارة المشاريع لدينا بالكامل. عملي وإقليمي وأُنجز فعلًا — أمر نادر في التعلّم عبر الإنترنت."), name: "Yara Adel", role: L("Head of PMO · Fintech, Cairo", "رئيسة مكتب المشاريع · فنتك، القاهرة"), initial: "Y", color: "teal" },
  { quote: L("We rolled HElbaron out to 400 staff across three countries. SSO, reporting, and Arabic support just worked.", "أطلقنا HElbaron لـ400 موظف في ثلاث دول. الدخول الموحّد والتقارير والدعم العربي عملت ببساطة."), name: "Omar Farouk", role: L("L&D Director · Retail Group, Riyadh", "مدير التطوير · مجموعة تجزئة، الرياض"), initial: "O", color: "copper" },
  { quote: L("The AI for Decision Makers program paid for itself in a month. Content built for how we actually work.", "برنامج الذكاء الاصطناعي لصنّاع القرار عوّض تكلفته في شهر. محتوى مبنيّ على طريقة عملنا فعلًا."), name: "Nour Hassan", role: L("COO · Logistics, Dubai", "مديرة العمليات · لوجستيات، دبي"), initial: "N", color: "gold" },
];

export const instructorsHeading = {
  eyebrow: L("TAUGHT BY OPERATORS", "بقيادة ممارسين"),
  title1: L("Learn from people who have", "تعلّم ممّن"),
  title2: L("actually done it.", "فعلوها بالفعل."),
  subtitle: L("Instructors are vetted regional practitioners — founders, operators, and specialists, not career lecturers.", "المدرّبون ممارسون إقليميون مُختارون — مؤسّسون وممارسون ومتخصّصون، لا محاضرون محترفون."),
};

export const instructors = [
  { name: "Laila Mansour", initial: "L", color: "copper", field: L("Marketing Strategy", "استراتيجية التسويق"), credential: L("Ex-CMO · 2 exits", "مديرة تسويق سابقة · خروجان") },
  { name: "Karim Saleh", initial: "K", color: "teal", field: L("Finance & Analysis", "المالية والتحليل"), credential: L("15y CFO across MENA", "15 عامًا مديرًا ماليًا في المنطقة") },
  { name: "Hana Zaki", initial: "H", color: "gold", field: L("Entrepreneurship", "ريادة الأعمال"), credential: L("Founder · $40M raised", "مؤسِّسة · جمعت 40 مليون$") },
  { name: "Amir Gamal", initial: "A", color: "teal", field: L("Investment & Trading", "الاستثمار والتداول"), credential: L("Portfolio manager", "مدير محافظ") },
];

export const enterpriseHeading = {
  eyebrow: L("FOR ENTERPRISE & GOVERNMENT", "للمؤسسات والحكومات"),
  title1: L("Academies for teams", "أكاديميات للفرق"),
  title2: L("that must deliver.", "التي عليها أن تُنجز."),
  subtitle: L("Custom curricula, SSO, SCORM, dedicated success, and reporting — deployed for 75 organizations across the region.", "مناهج مخصّصة ودخول موحّد وSCORM ودعم نجاح مخصّص وتقارير — مطبّقة لـ75 مؤسسة في المنطقة."),
};

export const enterpriseTrust = [
  { icon: "ShieldCheck", label: L("SSO · SAML / OIDC", "دخول موحّد · SAML / OIDC") },
  { icon: "FileCheck2", label: L("SCORM & LMS export", "تصدير SCORM ونظام التعلّم") },
  { icon: "BarChart3", label: L("Impact reporting", "تقارير الأثر") },
  { icon: "Headset", label: L("Dedicated CSM & SLA", "مدير نجاح واتفاقية دعم") },
];

export const enterpriseMetrics = [
  { value: "75", label: L("Enterprise & gov clients", "عميل مؤسسي وحكومي") },
  { value: "30K+", label: L("Seats delivered", "مقعد مُقدّم") },
  { value: "98%", label: L("Renewal rate", "معدل التجديد") },
];
