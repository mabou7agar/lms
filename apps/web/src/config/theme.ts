/**
 * HElbaron brand + landing configuration ("frontend config layer").
 * Single source of truth for the visual identity and marketing content so the brand can later be
 * edited from an admin/dashboard settings screen. Colors here are display values (hex) mirroring
 * the CSS tokens in globals.css. Localized strings are { en, ar } pairs; pick with pickLocale().
 */

export type Localized = { en: string; ar: string };
export type Locale = "en" | "ar";
export const pickLocale = (v: Localized, locale: Locale): string => v[locale] ?? v.en;
export type LinkItem = { label: Localized; href: string };
export type Surface = "light" | "teal" | "copper";
export type Swatch = "teal" | "copper" | "gold" | "red";

export const brandTheme = {
  name: "HElbaron",
  tagline: { en: "Master the core. Lead the future.", ar: "أتقن الأساس. قُد المستقبل." } as Localized,
  logo: null as string | null,

  colors: {
    primary: "#134E4A",
    primaryForeground: "#F7F1E3",
    background: "#F7F1E3",
    card: "#FFFDF7",
    foreground: "#21302E",
    secondary: "#E7DEC9",
    copper: "#B85C38",
    gold: "#C9A24B",
    border: "#E4DBC9",
  },
  fonts: { heading: "Fraunces", body: "Inter" },
  radius: "0.75rem",
  buttonStyle: "solid" as "solid" | "outline" | "soft",

  announcement: {
    en: "INTERACTIVE ACADEMY  ·  HElbaron — the premium business academy for MENA",
    ar: "أكاديمية تفاعلية  ·  HElbaron — أكاديمية الأعمال المتميّزة للمنطقة",
  } as Localized,

  nav: [
    { label: { en: "Courses", ar: "الدورات" }, href: "/courses" },
    { label: { en: "Cohorts", ar: "الأفواج" }, href: "/cohorts" },
    { label: { en: "Workshops", ar: "الورش" }, href: "/workshops" },
    { label: { en: "Events", ar: "الفعاليات" }, href: "/events" },
    { label: { en: "B2B / B2G Training", ar: "تدريب المؤسسات" }, href: "/enterprise" },
    { label: { en: "Consulting", ar: "الاستشارات" }, href: "/advisory" },
    { label: { en: "Blog", ar: "المدوّنة" }, href: "/blog" },
  ] as LinkItem[],

  hero: {
    eyebrow: { en: "FOR MENA'S BUSINESS BUILDERS", ar: "لصنّاع الأعمال في المنطقة" } as Localized,
    headlineLine1: { en: "Master", ar: "أتقن" } as Localized,
    headlineEmphasis: { en: "the core.", ar: "الأساس." } as Localized,
    headlineLine2: { en: "Lead the future.", ar: "قُد المستقبل." } as Localized,
    subtitle: {
      en: "Twelve disciplines. One academy. Built for MENA professionals, founders, and enterprises. Courses, cohorts, workshops, enterprise training, and advisory — under one roof.",
      ar: "اثنتا عشرة تخصصًا. أكاديمية واحدة. مصمّمة لمحترفي وروّاد ومؤسسات المنطقة. دورات وأفواج وورش وتدريب مؤسسي واستشارات — تحت سقف واحد.",
    } as Localized,
    primaryCta: { label: { en: "Explore courses", ar: "استكشف الدورات" }, href: "/courses" } as LinkItem,
    secondaryCta: { label: { en: "HElbaron Advisory", ar: "استشارات HElbaron" }, href: "/advisory" } as LinkItem,
    rating: {
      value: "4.8",
      text: { en: "Trusted by 25,000+ learners and 75 enterprises across MENA", ar: "موثوق من أكثر من 25,000 متعلّم و75 مؤسسة في المنطقة" } as Localized,
    },
    cards: [
      { eyebrow: { en: "12 CATEGORIES", ar: "12 تصنيفًا" }, body: { en: "100+ courses across PM, AI, Leadership, Finance, and more", ar: "أكثر من 100 دورة في إدارة المشاريع والذكاء الاصطناعي والقيادة والمالية والمزيد" }, variant: "light" as Surface },
      { eyebrow: { en: "LIVE COHORTS", ar: "أفواج مباشرة" }, body: { en: "19 cohort programs · 65% completion rate", ar: "19 برنامج فوج · معدل إتمام 65%" }, variant: "teal" as Surface },
      { eyebrow: { en: "B2B / B2G TRAINING", ar: "تدريب المؤسسات والحكومات" }, body: { en: "75 enterprise customers · custom programs", ar: "75 عميلًا مؤسسيًا · برامج مخصّصة" }, variant: "copper" as Surface },
      { eyebrow: { en: "HELBARON ADVISORY", ar: "استشارات HElbaron" }, body: { en: "Strategy, ops, BD consulting", ar: "استشارات في الاستراتيجية والعمليات وتطوير الأعمال" }, variant: "light" as Surface },
    ],
  },

  serviceHeading: {
    eyebrow: { en: "WHAT WE OFFER", ar: "ما نقدّمه" } as Localized,
    title1: { en: "One academy.", ar: "أكاديمية واحدة." } as Localized,
    title2: { en: "Five service lines.", ar: "خمس خدمات." } as Localized,
    subtitle: {
      en: "From individual learning to enterprise transformation to strategic advisory.",
      ar: "من التعلّم الفردي إلى تحوّل المؤسسات إلى الاستشارات الاستراتيجية.",
    } as Localized,
  },
  serviceLines: [
    { no: "01", icon: "courses", fill: "light" as Surface, name: { en: "Courses", ar: "الدورات" }, desc: { en: "100+ on-demand courses across 12 verticals. $15–35/course or $12–45/month subscription.", ar: "أكثر من 100 دورة عند الطلب عبر 12 مجالًا. من 15–35$ للدورة أو 12–45$ شهريًا." }, cta: { en: "Browse catalog", ar: "تصفّح الكتالوج" }, href: "/courses" },
    { no: "02", icon: "cohorts", fill: "light" as Surface, name: { en: "Live Cohorts", ar: "الأفواج المباشرة" }, desc: { en: "19 cohort programs. 8–12 week intensives with MENA practitioners. $200–700.", ar: "19 برنامج فوج. مكثّفات من 8–12 أسبوعًا مع ممارسين من المنطقة. 200–700$." }, cta: { en: "View cohorts", ar: "عرض الأفواج" }, href: "/cohorts" },
    { no: "03", icon: "workshops", fill: "light" as Surface, name: { en: "In-person Workshops", ar: "ورش حضورية" }, desc: { en: "1–2 day intensives in Cairo, Dubai, Riyadh. $85–680. Small groups, hands-on.", ar: "مكثّفات ليوم أو يومين في القاهرة ودبي والرياض. 85–680$. مجموعات صغيرة وعملية." }, cta: { en: "Find workshops", ar: "ابحث عن الورش" }, href: "/workshops" },
    { no: "04", icon: "enterprise", fill: "teal" as Surface, name: { en: "B2B / B2G Training", ar: "تدريب المؤسسات والحكومات" }, desc: { en: "Enterprise & government custom programs. $8K–$2M. SSO, SCORM, dedicated CSM.", ar: "برامج مخصّصة للمؤسسات والحكومات. من 8 آلاف إلى 2 مليون$. SSO وSCORM ومدير نجاح مخصّص." }, cta: { en: "Book a demo", ar: "احجز عرضًا" }, href: "/enterprise" },
    { no: "05", icon: "advisory", fill: "copper" as Surface, name: { en: "HElbaron Advisory", ar: "استشارات HElbaron" }, desc: { en: "Business + BD consulting. Strategy, ops, partnerships. $8K–$2M engagements.", ar: "استشارات أعمال وتطوير أعمال. استراتيجية وعمليات وشراكات. مشاريع من 8 آلاف إلى 2 مليون$." }, cta: { en: "Talk to advisory", ar: "تحدّث مع الاستشارات" }, href: "/advisory" },
  ],

  verticalsHeading: {
    eyebrow: { en: "CURRICULUM", ar: "المنهج" } as Localized,
    title1: { en: "Twelve verticals.", ar: "اثنا عشر مجالًا." } as Localized,
    title2: { en: "MENA-focused.", ar: "تركيز على المنطقة." } as Localized,
    subtitle: {
      en: "Every category answers a specific demand signal in the MENA market.",
      ar: "كل تصنيف يلبّي إشارة طلب محدّدة في سوق المنطقة.",
    } as Localized,
  },
  categories: [
    { code: "PM", color: "teal" as Swatch, name: { en: "Project Management", ar: "إدارة المشاريع" }, count: { en: "9 courses · 3 cohorts", ar: "9 دورات · 3 أفواج" } },
    { code: "AG", color: "copper" as Swatch, name: { en: "Agile Mindset", ar: "العقلية الرشيقة" }, count: { en: "8 courses · 2 cohorts", ar: "8 دورات · فوجان" } },
    { code: "BD", color: "teal" as Swatch, name: { en: "Business Development", ar: "تطوير الأعمال" }, count: { en: "8 courses · 2 cohorts", ar: "8 دورات · فوجان" } },
    { code: "ST", color: "teal" as Swatch, name: { en: "Business Strategies", ar: "استراتيجيات الأعمال" }, count: { en: "7 courses · 2 cohorts", ar: "7 دورات · فوجان" } },
    { code: "EN", color: "copper" as Swatch, hot: true, name: { en: "Entrepreneurship", ar: "ريادة الأعمال" }, count: { en: "8 courses · 3 cohorts", ar: "8 دورات · 3 أفواج" } },
    { code: "BS", color: "teal" as Swatch, name: { en: "Business Skills", ar: "مهارات الأعمال" }, count: { en: "8 courses", ar: "8 دورات" } },
    { code: "LD", color: "teal" as Swatch, name: { en: "Leadership", ar: "القيادة" }, count: { en: "8 courses · 2 cohorts", ar: "8 دورات · فوجان" } },
    { code: "MK", color: "gold" as Swatch, name: { en: "Marketing Strategies", ar: "استراتيجيات التسويق" }, count: { en: "8 courses · 2 cohorts", ar: "8 دورات · فوجان" } },
    { code: "SL", color: "red" as Swatch, name: { en: "Sales Management", ar: "إدارة المبيعات" }, count: { en: "8 courses · 2 cohorts", ar: "8 دورات · فوجان" } },
    { code: "FN", color: "copper" as Swatch, name: { en: "Finance & Analysis", ar: "المالية والتحليل" }, count: { en: "8 courses · 2 cohorts", ar: "8 دورات · فوجان" } },
    { code: "AI", color: "gold" as Swatch, hot: true, name: { en: "Business AI", ar: "الذكاء الاصطناعي للأعمال" }, count: { en: "8 courses · 3 cohorts", ar: "8 دورات · 3 أفواج" } },
    { code: "IT", color: "teal" as Swatch, hot: true, name: { en: "Investment & Trading", ar: "الاستثمار والتداول" }, count: { en: "9 courses · 2 cohorts", ar: "9 دورات · فوجان" } },
  ],

  stats: [
    { display: "100+", num: 100, prefix: "", suffix: "+", label: { en: "Courses live", ar: "دورة متاحة" } },
    { display: "25K+", num: 25, prefix: "", suffix: "K+", label: { en: "Learners across MENA", ar: "متعلّم في المنطقة" } },
    { display: "75", num: 75, prefix: "", suffix: "", label: { en: "Enterprise customers", ar: "عميل مؤسسي" } },
    { display: "$25M", num: 25, prefix: "$", suffix: "M", label: { en: "Year-3 target revenue", ar: "الإيراد المستهدف للسنة الثالثة" } },
  ],

  finalCta: {
    title1: { en: "Master the core.", ar: "أتقن الأساس." } as Localized,
    title2: { en: "Lead the future.", ar: "قُد المستقبل." } as Localized,
    subtitle: {
      en: "Start with one course, grow into a cohort, scale to enterprise training, partner on advisory. The full journey, one academy.",
      ar: "ابدأ بدورة واحدة، وتوسّع إلى فوج، ثم إلى تدريب مؤسسي، وشارك في الاستشارات. الرحلة الكاملة في أكاديمية واحدة.",
    } as Localized,
    primary: { label: { en: "Start free trial", ar: "ابدأ نسخة تجريبية مجانية" }, href: "/register" } as LinkItem,
    secondary: { label: { en: "Talk to enterprise", ar: "تحدّث مع فريق المؤسسات" }, href: "/enterprise" } as LinkItem,
  },

  ctas: {
    signIn: { en: "Sign in", ar: "تسجيل الدخول" } as Localized,
    startFree: { en: "Start free", ar: "ابدأ مجانًا" } as Localized,
  },

  footer: {
    description: {
      en: "Master the core. Lead the future. The MENA business academy for individuals, teams, and enterprises across twelve verticals.",
      ar: "أتقن الأساس. قُد المستقبل. أكاديمية الأعمال للمنطقة للأفراد والفرق والمؤسسات عبر اثني عشر مجالًا.",
    } as Localized,
    locations: ["Cairo", "Dubai", "Riyadh"],
    columns: [
      {
        title: { en: "Learn", ar: "تعلّم" } as Localized,
        links: [
          { label: { en: "Courses", ar: "الدورات" }, href: "/courses" },
          { label: { en: "Live cohorts", ar: "الأفواج" }, href: "/cohorts" },
          { label: { en: "Workshops", ar: "الورش" }, href: "/workshops" },
          { label: { en: "Events", ar: "الفعاليات" }, href: "/events" },
          { label: { en: "Certificates", ar: "الشهادات" }, href: "/verify" },
          { label: { en: "Pricing", ar: "الأسعار" }, href: "/pricing" },
          { label: { en: "Become an instructor", ar: "كن مدرّبًا" }, href: "/teach/apply" },
        ],
      },
      {
        title: { en: "For Business", ar: "للأعمال" } as Localized,
        links: [
          { label: { en: "B2B / B2G Training", ar: "تدريب المؤسسات" }, href: "/enterprise" },
          { label: { en: "HElbaron Advisory", ar: "استشارات HElbaron" }, href: "/advisory" },
          { label: { en: "Government partnerships", ar: "شراكات حكومية" }, href: "/enterprise" },
          { label: { en: "Case studies", ar: "دراسات حالة" }, href: "/enterprise" },
        ],
      },
      {
        title: { en: "Company", ar: "الشركة" } as Localized,
        links: [
          { label: { en: "About", ar: "من نحن" }, href: "/about" },
          { label: { en: "Organizations", ar: "المؤسسات" }, href: "/enterprise" },
          { label: { en: "Trainers", ar: "المدرّبون" }, href: "/trainers" },
          { label: { en: "Contact", ar: "تواصل" }, href: "/contact" },
        ],
      },
    ] as { title: Localized; links: LinkItem[] }[],
    legal: [
      { label: { en: "Verify certificate", ar: "التحقق من الشهادات" }, href: "/verify" },
      { label: { en: "Privacy", ar: "الخصوصية" }, href: "/privacy" },
      { label: { en: "Terms", ar: "الشروط" }, href: "/terms" },
    ] as LinkItem[],
  },

  trustedBy: {
    label: { en: "Trusted by teams at", ar: "موثوق من فرق في" } as Localized,
    logos: ["Nile Group", "Gulf Ventures", "Levant Bank", "Delta Foods", "Atlas Energy", "Cedar Health", "Sahara Retail", "Bosphorus Tech", "Marina Logistics"],
  },

  servicePages: {
    cohorts: {
      eyebrow: { en: "LIVE COHORTS", ar: "الأفواج المباشرة" },
      title: { en: "Learn together.", ar: "تعلّموا معًا." },
      emphasis: { en: "Finish together.", ar: "وأنهوا معًا." },
      subtitle: { en: "8-12 week cohort programs led by MENA practitioners, with mentors, live sessions, and completion tracking.", ar: "برامج أفواج من 8 إلى 12 أسبوعًا يقودها ممارسون من المنطقة، مع مرشدين وجلسات مباشرة وتتبّع للإنجاز." },
      primaryCta: { label: { en: "Browse cohorts", ar: "تصفّح الأفواج" }, href: "/courses" },
      secondaryCta: { label: { en: "Talk to us", ar: "تواصل معنا" }, href: "/advisory" },
      features: [
        { icon: "Users", title: { en: "Mentor-led", ar: "بإشراف مرشدين" }, desc: { en: "Small groups guided by practitioners who have done the work.", ar: "مجموعات صغيرة يقودها ممارسون خبروا العمل فعلًا." } },
        { icon: "CalendarClock", title: { en: "8-12 weeks", ar: "8-12 أسبوعًا" }, desc: { en: "Structured, part-time schedules that fit around work.", ar: "جداول منظّمة بدوام جزئي تناسب العمل." } },
        { icon: "Video", title: { en: "Live sessions", ar: "جلسات مباشرة" }, desc: { en: "Weekly live workshops, Q&A, and office hours.", ar: "ورش أسبوعية مباشرة وأسئلة وساعات مكتبية." } },
        { icon: "CheckCircle2", title: { en: "Completion tracking", ar: "تتبّع الإنجاز" }, desc: { en: "Milestones and progress so you actually finish.", ar: "مراحل ومؤشرات تقدّم لتُنهي فعلًا." } },
        { icon: "MessageSquare", title: { en: "Peer community", ar: "مجتمع الأقران" }, desc: { en: "A cohort of peers building alongside you.", ar: "فوج من الأقران يبنون بجانبك." } },
        { icon: "Award", title: { en: "Certificate", ar: "شهادة" }, desc: { en: "A verifiable certificate on completion.", ar: "شهادة قابلة للتحقّق عند الإتمام." } },
      ],
      highlights: [
        { num: 19, suffix: "", label: { en: "Cohort programs", ar: "برنامج فوج" } },
        { num: 65, suffix: "%", label: { en: "Completion rate", ar: "معدل الإتمام" } },
        { num: 24, suffix: "", label: { en: "Active mentors", ar: "مرشد نشط" } },
      ],
    },
    workshops: {
      eyebrow: { en: "IN-PERSON WORKSHOPS", ar: "ورش حضورية" },
      title: { en: "Hands-on.", ar: "تطبيق عملي." },
      emphasis: { en: "In the room.", ar: "داخل القاعة." },
      subtitle: { en: "1-2 day intensives in Cairo, Dubai, and Riyadh. Small groups, real practice, immediate feedback.", ar: "مكثّفات ليوم أو يومين في القاهرة ودبي والرياض. مجموعات صغيرة وتطبيق حقيقي وتغذية راجعة فورية." },
      primaryCta: { label: { en: "Find workshops", ar: "ابحث عن الورش" }, href: "/courses" },
      secondaryCta: { label: { en: "Bring to your team", ar: "قدّمها لفريقك" }, href: "/enterprise" },
      features: [
        { icon: "Users", title: { en: "Small groups", ar: "مجموعات صغيرة" }, desc: { en: "Capped seats for real attention and practice.", ar: "مقاعد محدودة لاهتمام وتطبيق حقيقي." } },
        { icon: "Wrench", title: { en: "Hands-on labs", ar: "معامل تطبيقية" }, desc: { en: "Work on real scenarios, not slides.", ar: "اعمل على حالات حقيقية لا شرائح." } },
        { icon: "Presentation", title: { en: "Expert facilitators", ar: "ميسّرون خبراء" }, desc: { en: "Led by operators from the region.", ar: "بقيادة ممارسين من المنطقة." } },
        { icon: "MapPin", title: { en: "Three cities", ar: "ثلاث مدن" }, desc: { en: "Cairo, Dubai, and Riyadh venues.", ar: "قاعات في القاهرة ودبي والرياض." } },
        { icon: "Package", title: { en: "Materials included", ar: "المواد مشمولة" }, desc: { en: "Toolkits and templates to take home.", ar: "أدوات وقوالب تأخذها معك." } },
        { icon: "Handshake", title: { en: "Networking", ar: "تواصل مهني" }, desc: { en: "Meet peers and partners in person.", ar: "قابل الأقران والشركاء وجهًا لوجه." } },
      ],
      highlights: [
        { num: 3, suffix: "", label: { en: "Cities", ar: "مدن" } },
        { num: 12, suffix: "", label: { en: "Avg. group size", ar: "متوسط حجم المجموعة" } },
        { num: 48, suffix: "", label: { en: "Workshops / year", ar: "ورشة سنويًا" } },
      ],
    },
    enterprise: {
      eyebrow: { en: "B2B / B2G TRAINING", ar: "تدريب المؤسسات والحكومات" },
      title: { en: "Upskill your", ar: "طوّر مهارات" },
      emphasis: { en: "whole organization.", ar: "مؤسستك بالكامل." },
      subtitle: { en: "Custom training programs for enterprises and government teams. SSO, SCORM, dedicated success, and reporting.", ar: "برامج تدريب مخصّصة للمؤسسات والفرق الحكومية. تسجيل دخول موحّد وSCORM ودعم نجاح مخصّص وتقارير." },
      primaryCta: { label: { en: "Book a demo", ar: "احجز عرضًا" }, href: "/advisory" },
      secondaryCta: { label: { en: "See case studies", ar: "اطّلع على دراسات الحالة" }, href: "/advisory" },
      features: [
        { icon: "Layers", title: { en: "Custom curriculum", ar: "منهج مخصّص" }, desc: { en: "Programs mapped to your goals and roles.", ar: "برامج مصمّمة على أهدافك وأدوارك." } },
        { icon: "ShieldCheck", title: { en: "SSO & security", ar: "دخول موحّد وأمان" }, desc: { en: "SAML/OIDC SSO and enterprise controls.", ar: "دخول موحّد SAML/OIDC وضوابط مؤسسية." } },
        { icon: "FileText", title: { en: "SCORM & LMS", ar: "SCORM ونظام تعلّم" }, desc: { en: "Deploy inside your own LMS if needed.", ar: "انشر داخل نظام التعلّم الخاص بك عند الحاجة." } },
        { icon: "Headset", title: { en: "Dedicated CSM", ar: "مدير نجاح مخصّص" }, desc: { en: "A named success manager for your account.", ar: "مدير نجاح مخصّص لحسابك." } },
        { icon: "BarChart3", title: { en: "Reporting", ar: "تقارير" }, desc: { en: "Progress, completion, and impact analytics.", ar: "تحليلات التقدّم والإتمام والأثر." } },
        { icon: "LifeBuoy", title: { en: "SLA support", ar: "دعم باتفاقية مستوى" }, desc: { en: "Priority support with response SLAs.", ar: "دعم ذو أولوية باتفاقيات استجابة." } },
      ],
      highlights: [
        { num: 75, suffix: "", label: { en: "Enterprise clients", ar: "عميل مؤسسي" } },
        { num: 30, suffix: "K+", label: { en: "Seats delivered", ar: "مقعد مُقدّم" } },
        { num: 98, suffix: "%", label: { en: "Renewal rate", ar: "معدل التجديد" } },
      ],
    },
    advisory: {
      eyebrow: { en: "HELBARON ADVISORY", ar: "استشارات HElbaron" },
      title: { en: "Strategy that", ar: "استراتيجية" },
      emphasis: { en: "ships.", ar: "تُنفَّذ فعلًا." },
      subtitle: { en: "Business and BD consulting for MENA. Strategy, operations, partnerships, and go-to-market - hands-on, not slideware.", ar: "استشارات أعمال وتطوير أعمال للمنطقة. استراتيجية وعمليات وشراكات ودخول للسوق — تطبيق عملي لا شرائح." },
      primaryCta: { label: { en: "Talk to advisory", ar: "تحدّث مع الاستشارات" }, href: "/advisory" },
      secondaryCta: { label: { en: "Our services", ar: "خدماتنا" }, href: "/enterprise" },
      features: [
        { icon: "Compass", title: { en: "Strategy", ar: "الاستراتيجية" }, desc: { en: "Positioning, roadmaps, and growth models.", ar: "التموضع وخرائط الطريق ونماذج النمو." } },
        { icon: "Settings", title: { en: "Operations", ar: "العمليات" }, desc: { en: "Process, org design, and execution rhythm.", ar: "العمليات وتصميم المؤسسة وإيقاع التنفيذ." } },
        { icon: "TrendingUp", title: { en: "Business development", ar: "تطوير الأعمال" }, desc: { en: "Pipeline, pricing, and revenue systems.", ar: "المسار والتسعير وأنظمة الإيراد." } },
        { icon: "Handshake", title: { en: "Partnerships", ar: "الشراكات" }, desc: { en: "Channel, alliances, and B2G access.", ar: "القنوات والتحالفات والوصول الحكومي." } },
        { icon: "Rocket", title: { en: "Go-to-market", ar: "دخول السوق" }, desc: { en: "Launch plans built for the region.", ar: "خطط إطلاق مصمّمة للمنطقة." } },
        { icon: "GraduationCap", title: { en: "Fractional leadership", ar: "قيادة بدوام جزئي" }, desc: { en: "Embedded senior operators when you need them.", ar: "قادة كبار مدمجون عند الحاجة." } },
      ],
      highlights: [
        { num: 40, suffix: "+", label: { en: "Engagements", ar: "مشروع" } },
        { num: 12, suffix: "", label: { en: "Verticals covered", ar: "مجالًا مغطّى" } },
        { num: 3, suffix: "", label: { en: "Regional hubs", ar: "مراكز إقليمية" } },
      ],
    },
  },
};

export type BrandTheme = typeof brandTheme;
