#!/usr/bin/env python3
"""Generate languages/xpay-for-woocommerce-ar.po from the .pot.

Parses the POT so msgids stay byte-exact, fills translations from the
dictionary below, and fails loudly on any string the dictionary misses.
"""
import re
import sys

from pathlib import Path

# Resolved from this file's location, so the generator works in any
# checkout: tools/make-ar-po.py -> <repo>/languages/.
_LANG = Path(__file__).resolve().parent.parent / "languages"
POT = str(_LANG / "xpay-for-woocommerce.pot")
OUT = str(_LANG / "xpay-for-woocommerce-ar.po")

AR = {
    'Which payment methods shoppers see is set in your XPay account, not here.':
        'طرق الدفع التي يراها المشتري تُحدَّد من حساب XPay الخاص بك، وليس من هنا.',
    'Match my store': 'مطابقة متجري',
    'Always light': 'فاتح دائمًا',
    'Always dark': 'داكن دائمًا',
    'Order total': 'إجمالي الطلب',
    'Payment is unavailable right now. Please try again in a moment.':
        'الدفع غير متاح الآن. برجاء المحاولة بعد قليل.',
    'Your order total changed. Check the new total and try again.':
        'تغيّر إجمالي طلبك. راجع الإجمالي الجديد ثم حاول مرة أخرى.',
    'The payment fields are still loading. Please try again in a moment.':
        'حقول الدفع ما زالت قيد التحميل. برجاء المحاولة بعد قليل.',
    'Please finish filling in the payment details before placing your order.':
        'برجاء استكمال بيانات الدفع قبل تأكيد الطلب.',
    'This payment session expired. Refresh the page to start a new one.':
        'انتهت صلاحية جلسة الدفع هذه. حدِّث الصفحة لبدء جلسة جديدة.',
    'This payment has already been completed.': 'تم إتمام هذا الدفع بالفعل.',
    'Mobile number registered with ValU': 'رقم الموبايل المسجَّل لدى ڤاليو',
    'ValU charges the mobile number registered with it, and the number on this order is not an Egyptian or Jordanian mobile. Enter the one your ValU account uses.':
        'ڤاليو تخصم من رقم الموبايل المسجَّل لديها، والرقم الموجود على هذا الطلب ليس رقم موبايل مصري أو أردني. أدخل الرقم المستخدَم في حساب ڤاليو الخاص بك.',
    'ValU charges the mobile number registered with it. Enter the Egyptian or Jordanian mobile your ValU account uses.':
        'ڤاليو تخصم من رقم الموبايل المسجَّل لديها. أدخل رقم الموبايل المصري أو الأردني المستخدَم في حساب ڤاليو الخاص بك.',
    "XPay's payment fields appear on your checkout page. Which methods a shopper sees is decided by your XPay account, not here.":
        'تظهر حقول دفع XPay داخل صفحة الدفع في متجرك. طرق الدفع التي يراها المشتري تُحدَّد من حساب XPay الخاص بك، وليس من هنا.',
    'Theme': 'المظهر',
    "Automatic matches your store's own colours, fonts and corners, and follows the shopper's device for light or dark. Choose Light or Dark to fix it.":
        'الوضع التلقائي يطابق ألوان متجرك وخطوطه وزواياه، ويتبع جهاز المشتري في الفاتح أو الداكن. اختر فاتح أو داكن لتثبيته.',
    'Automatic (match my store)': 'تلقائي (مطابقة متجري)',
    "XPay for WooCommerce": "XPay لـ WooCommerce",
    "https://xpay.app/": "https://xpay.app/",
    "Accept payments on your WooCommerce store via XPay (Egypt): cards, ValU and more, in a secure on-site checkout.":
        "استقبل المدفوعات في متجر WooCommerce الخاص بك عبر XPay (مصر) — البطاقات وڤاليو والمزيد، في صفحة دفع آمنة داخل متجرك.",
    "XPay": "XPay",
    "XPay Log": "سجل XPay",
    "Diagnostic logging is off. Turn it on in WooCommerce → Settings → Payments → XPay to record new entries.":
        "التسجيل التشخيصي متوقف. فعِّله من WooCommerce ← الإعدادات ← المدفوعات ← XPay لتسجيل إدخالات جديدة.",
    "Order #": "رقم الطلب",
    "Request id": "معرّف الطلب (request)",
    "Stage starts with": "المرحلة تبدأ بـ",
    "Filter": "تصفية",
    "Copied. Paste it into your support ticket": "تم النسخ — ألصقه في تذكرة الدعم الخاصة بك",
    "Copy debug report": "نسخ تقرير التشخيص",
    "Time (UTC)": "الوقت (UTC)",
    "Request": "المعرّف",
    "Stage": "المرحلة",
    "Order": "الطلب",
    "Details": "التفاصيل",
    "No log entries match. New entries appear here as payments run.":
        "لا توجد إدخالات مطابقة في السجل. ستظهر الإدخالات الجديدة هنا مع تشغيل المدفوعات.",
    "Delete all XPay log entries? This cannot be undone.":
        "هل تريد حذف جميع إدخالات سجل XPay؟ لا يمكن التراجع عن هذا الإجراء.",
    "Clear log": "مسح السجل",
    "Search messages and details": "ابحث في الرسائل والتفاصيل",
    "Search": "بحث",
    "From date (UTC)": "من تاريخ (UTC)",
    "To date (UTC)": "إلى تاريخ (UTC)",
    "Clear filters": "مسح عوامل التصفية",
    "Export CSV": "تصدير CSV",
    "You are not allowed to export the XPay log.": "غير مسموح لك بتصدير سجل XPay.",
    "Accept payments in Egypt, on your own store": "استقبل المدفوعات في مصر، على متجرك أنت",
    "Cards, ValU and Fawry open in a secure XPay window over your checkout. Shoppers never leave your site, and orders are confirmed only by signed webhooks.":
        "تُفتح مدفوعات البطاقات وڤاليو وفوري في نافذة XPay آمنة فوق صفحة الدفع لديك — لا يغادر المتسوقون موقعك أبدًا، ولا تُؤكد الطلبات إلا عبر إشعارات webhook موقَّعة.",
    "Payment methods": "طرق الدفع",
    "Payment Methods": "طرق الدفع",
    "Change display order": "تغيير ترتيب العرض",
    "Save display order": "حفظ ترتيب العرض",
    "Payment methods menu": "قائمة طرق الدفع",
    "Refresh payment methods": "تحديث طرق الدفع",
    "Live mode cannot be enabled before you have connected a live XPay account.":
        "لا يمكن تفعيل الوضع الفعلي قبل ربط حساب XPay فعلي.",
    "Test mode cannot be enabled before you have connected a test XPay account.":
        "لا يمكن تفعيل الوضع التجريبي قبل ربط حساب XPay تجريبي.",
    "Connect your live account": "اربط حسابك الفعلي",
    "Connect your test account": "اربط حسابك التجريبي",
    "Payment fields use the branding from your XPay dashboard. Automatic matches your checkout theme.":
        "تستخدم حقول الدفع إعدادات العلامة التجارية من لوحة تحكم XPay. الوضع التلقائي يطابق مظهر صفحة الدفع.",
    "The payment fields are styled by your XPay dashboard branding. Automatic picks light or dark from your checkout page's own background. Choose Light or Dark to fix it.":
        "تأخذ حقول الدفع تصميمها من إعدادات العلامة التجارية في لوحة تحكم XPay. الوضع التلقائي يختار الفاتح أو الداكن حسب خلفية صفحة إتمام الشراء نفسها. اختر فاتحًا أو داكنًا لتثبيته.",
    "Automatic (match the page)": "تلقائي (مطابق للصفحة)",
    "Your order is reserved and waiting for your payment reference to be paid, for example at a Fawry point or in the Fawry app. We confirm automatically the moment it is paid and email you then. Nothing ships before that confirmation.":
        "طلبك محجوز في انتظار سداد مرجع الدفع الخاص بك، مثلًا لدى أي منفذ فوري أو في تطبيق فوري. نؤكد الدفع تلقائيًا فور سداده ونرسل لك بريدًا حينها. لن يُشحن شيء قبل هذا التأكيد.",
    "Your payment is still being confirmed. Usually under a minute. We will email you the moment it is confirmed.":
        "ما زال دفعك قيد التأكيد. عادةً أقل من دقيقة. سنرسل لك بريدًا فور تأكيده.",
    "Choose which payment methods appear at checkout. Manage available methods in your XPay dashboard.":
        "اختر طرق الدفع التي تظهر عند إتمام الطلب. أدر الطرق المتاحة من لوحة تحكم XPay.",
    "This only changes the order of XPay methods. Manage all payment methods in WooCommerce → Settings → Payments.":
        "هذا يغيّر ترتيب طرق XPay فقط. أدر جميع طرق الدفع من WooCommerce ← الإعدادات ← المدفوعات.",
    "The method list changed while you were editing. Reload the page and try again.":
        "تغيّرت قائمة طرق الدفع أثناء التعديل. أعد تحميل الصفحة وحاول مرة أخرى.",
    "Display order saved.": "تم حفظ ترتيب العرض.",
    "XPay: keep at least one payment method on. Your payment method changes were not saved. To take XPay off the checkout entirely, turn off Enable XPay instead.":
        "XPay: أبقِ طريقة دفع واحدة على الأقل مفعّلة. لم تُحفظ تغييرات طرق الدفع. لإزالة XPay من صفحة إتمام الشراء بالكامل، أوقف تفعيل XPay بدلًا من ذلك.",
    "Refunds": "المبالغ المستردة",
    "Full & partial, in WooCommerce": "استرداد كامل وجزئي، من داخل WooCommerce",
    "Languages": "اللغات",
    "Arabic & English, RTL ready": "العربية والإنجليزية، مع دعم الاتجاه من اليمين إلى اليسار",
    "Visa, Mastercard, Meeza": "فيزا وماستركارد وميزة",
    "Activate XPay": "تفعيل XPay",
    "New to XPay?": "جديد على XPay؟",
    "Create your merchant account →": "أنشئ حساب التاجر الخاص بك ←",
    "How it works": "كيف يعمل",
    "The shopper clicks Place order and the XPay window opens over your own payment page.":
        "يضغط المتسوق على إتمام الطلب فتُفتح نافذة XPay فوق صفحة الدفع الخاصة بمتجرك.",
    "Card details are entered inside XPay's PCI-certified window. They never touch your server.":
        "تُدخل بيانات البطاقة داخل نافذة XPay المعتمدة وفق معايير PCI — ولا تصل إلى خادمك أبدًا.",
    "A cryptographically signed webhook marks the order paid, and the receipt is stamped PAID.":
        "إشعار webhook موقَّع تشفيريًا هو ما يُعلّم الطلب كمدفوع، ويُختم الإيصال بعلامة \"مدفوع\".",
    "Docs & help": "الأدلة والمساعدة",
    "Getting started guide": "دليل البدء",
    "Payment was not completed. Your order is saved, and you can try again.":
        "لم تكتمل عملية الدفع. طلبك محفوظ، ويمكنك المحاولة مرة أخرى.",
    "Last decline: %1$s (%2$s)": "آخر رفض: %1$s (%2$s)",
    "XPay payment attempt declined [%1$s]: %2$s The shopper can retry; the order is unchanged.":
        "رُفضت محاولة دفع عبر XPay [%1$s]: %2$s يمكن للمتسوق إعادة المحاولة، والطلب لم يتغير.",
    "XPay received a SECOND payment for this order, on an outdated payment session (%1$s, payment intent %2$s). Refund the duplicate payment from your XPay dashboard.":
        "استلمت XPay دفعة ثانية لهذا الطلب على جلسة دفع قديمة (%1$s، نية الدفع %2$s). استرد الدفعة المكررة من لوحة تحكم XPay الخاصة بك.",
    "XPay received a payment for this order on an outdated payment session (%s), so the amount may not match the current total. Review the payment in your XPay dashboard, then complete or refund the order manually.":
        "استلمت XPay دفعة لهذا الطلب على جلسة دفع قديمة (%s)، لذا قد لا يطابق المبلغ الإجمالي الحالي. راجع عملية الدفع في لوحة تحكم XPay، ثم أكمل الطلب أو استرد المبلغ يدويًا.",
    "XPay dashboard refund %s": "استرداد من لوحة تحكم XPay %s",
    "XPay refunded this order from the dashboard (%1$s), but the refund could not be recorded here: %2$s. Record it manually.":
        "استردت XPay مبلغ هذا الطلب من لوحة التحكم (%1$s)، لكن تعذّر تسجيل الاسترداد هنا: %2$s. سجّله يدويًا.",
    "XPay refund of %1$s issued from your XPay dashboard was recorded here (%2$s).":
        "تم هنا تسجيل استرداد XPay بمبلغ %1$s الصادر من لوحة تحكم XPay الخاصة بك (%2$s).",
    "XPay refunded this order from the dashboard (%s), in a currency this order is not in. Check the amount in your XPay dashboard and record the refund here manually.":
        "استردت XPay مبلغ هذا الطلب من لوحة التحكم (%s) بعملة تختلف عن عملة الطلب. تحقق من المبلغ في لوحة تحكم XPay وسجّل الاسترداد هنا يدويًا.",
    "XPay refund %1$s failed (%2$s). No money was returned to the customer. Check the refund in your XPay dashboard.":
        "فشل استرداد XPay %1$s (%2$s). لم يُرَدّ أي مبلغ إلى العميل. تحقق من الاسترداد في لوحة تحكم XPay الخاصة بك.",
    "XPay: a payment in %s was rejected because your XPay account has no exchange rate configured for it. Every checkout will fail the same way until this is fixed. Contact XPay support to enable the currency, or change your store currency.":
        "XPay: رُفضت عملية دفع بعملة %s لأن حسابك في XPay لا يحتوي على سعر صرف مضبوط لها. ستفشل كل عمليات الدفع بالطريقة نفسها حتى يُحل هذا. تواصل مع دعم XPay لتفعيل العملة، أو غيّر عملة متجرك.",
    "Webhooks reference": "مرجع الويب هوك",
    "Going live checklist": "قائمة تجهيز التشغيل الفعلي",
    "Compatibility notes": "ملاحظات التوافق",
    "XPay guide": "دليل XPay",
    "XPay settings": "إعدادات XPay",
    "This guide is missing from the installed plugin files. Reinstall the plugin to restore it.":
        "هذا الدليل غير موجود ضمن ملفات الإضافة المثبتة. أعد تثبيت الإضافة لاستعادته.",
    "payment session check": "فحص جلسة الدفع",
    "This order has already been paid.": "هذا الطلب مدفوع بالفعل.",
    "View your order confirmation": "عرض تأكيد طلبك",
    "XPay completed refund %s with a different amount or currency than requested. Nothing was recorded in WooCommerce. Verify the payment in your XPay dashboard.":
        "أكملت XPay عملية الاسترداد %s بمبلغ أو عملة مختلفة عن المطلوب. لم يُسجَّل أي شيء في WooCommerce. تحقق من عملية الدفع في لوحة تحكم XPay الخاصة بك.",
    "This order is not in EGP. XPay processes refund amounts in EGP, so refunding from here would move the wrong amount. Issue the refund from your XPay dashboard instead, then record it in WooCommerce manually.":
        "هذا الطلب ليس بالجنيه المصري. تعالج XPay مبالغ الاسترداد بالجنيه المصري، لذا فإن الاسترداد من هنا سيحرّك مبلغًا خاطئًا. نفّذ الاسترداد من لوحة تحكم XPay الخاصة بك، ثم سجّله في WooCommerce يدويًا.",
    "XPay completed this refund with a different amount or currency than requested, so it was not recorded in WooCommerce. Check the payment in your XPay dashboard before doing anything else.":
        "أكملت XPay هذا الاسترداد بمبلغ أو عملة مختلفة عن المطلوب، لذلك لم يُسجَّل في WooCommerce. تحقق من عملية الدفع في لوحة تحكم XPay قبل أي خطوة أخرى.",
    "Could not reach XPay to confirm this refund. It may still have gone through: check the payment in your XPay dashboard before retrying.":
        "تعذّر الوصول إلى XPay لتأكيد هذا الاسترداد. قد يكون قد تم بالفعل: تحقق من عملية الدفع في لوحة تحكم XPay قبل إعادة المحاولة.",
    "Configuration reference": "مرجع الإعدادات",
    "Troubleshooting": "استكشاف الأخطاء وإصلاحها",
    "View the full entry": "عرض الإدخال كاملًا",
    "Log entry": "إدخال السجل",
    "Close": "إغلاق",
    "Copy entry": "نسخ الإدخال",
    "Copied": "تم النسخ",
    "All stages": "كل المراحل",
    "Sessions": "الجلسات",
    "Webhooks": "إشعارات webhook",
    "Orders": "الطلبات",
    "API calls": "استدعاءات API",
    "Customers": "العملاء",
    "Checkout errors": "أخطاء إتمام الدفع",
    "Order locks": "أقفال الطلبات",
    "Compatibility": "التوافق",
    "Showing the latest %d entries. Narrow with the filters, or Export CSV for everything retained.":
        "يتم عرض أحدث %d إدخال — ضيّق النتائج بعوامل التصفية، أو استخدم تصدير CSV لكل ما هو محفوظ.",
    "How to get your API keys": "كيف تحصل على مفاتيح API",
    "Open your XPay dashboard. In the left menu, at the very bottom, make sure the Test Mode toggle is on.":
        "افتح لوحة تحكم XPay — في أسفل القائمة الجانبية، تأكد أن مفتاح \"وضع الاختبار\" مفعّل.",
    "Open your XPay dashboard. In the left menu, at the very bottom, turn the Test Mode toggle off to switch to your live account.":
        "افتح لوحة تحكم XPay — في أسفل القائمة الجانبية، أطفئ مفتاح \"وضع الاختبار\" للتبديل إلى حسابك الفعلي.",
    "Open Developer hub from the bottom-left corner of the page, then the API keys tab.":
        "افتح \"منصة المطورين\" من الشريط أسفل الصفحة، ثم تبويب \"مفاتيح API\".",
    "Open Developer hub from the bottom-left corner of the page, then the Webhooks tab, and click Add endpoint.":
        "افتح \"منصة المطورين\" من الشريط أسفل الصفحة، ثم تبويب \"Webhooks\"، واضغط \"إضافة نقطة نهاية\".",
    "How to set up the webhook": "كيفية إعداد الـ webhook",
    "Get your API keys": "احصل على مفاتيح API",
    "Test Mode": "وضع الاختبار",
    "Live Mode": "الحساب الفعلي",
    "Test keys work on test data only. No real money ever moves.":
        "مفاتيح الاختبار تعمل على بيانات تجريبية فقط — لا يتحرك أي مال حقيقي أبدًا.",
    "Live keys move real money, and live mode unlocks only after XPay activates your account. If your dashboard still shows \"Request payment activation\", finish that first.":
        "المفاتيح الفعلية تحرّك أموالًا حقيقية، ولا يُفتح الحساب الفعلي إلا بعد أن تفعّل XPay حسابك — إذا كانت لوحة التحكم لا تزال تعرض \"طلب تفعيل المدفوعات\"، فأكمل التفعيل أولًا.",
    "Open your XPay dashboard and switch the toggle at the top to %s.":
        "افتح لوحة تحكم XPay وبدّل المفتاح في الأعلى إلى %s.",
    "In the left sidebar, open Developer hub, then the API keys tab.":
        "من الشريط الجانبي افتح \"منصة المطورين\"، ثم تبويب \"مفاتيح API\".",
    "Under Restricted keys, click Create restricted key: name it (for example, WooCommerce), allow Checkout Sessions and Refunds, then click Create key.":
        "تحت \"المفاتيح المقيدة\" اضغط \"إنشاء مفتاح مقيد\": سمِّه (مثلًا WooCommerce)، وامنح صلاحيتي Checkout Sessions وRefunds، ثم اضغط \"إنشاء المفتاح\".",
    "Copy the new key (it starts with %s) and paste it into the Secret key field here. Revealing a key later first emails you a 6-digit code.":
        "انسخ المفتاح الجديد — يبدأ بـ %s — وألصقه في حقل المفتاح السري هنا. كشف المفتاح لاحقًا يرسل أولًا رمزًا من 6 أرقام إلى بريدك.",
    "Copy the matching Publishable key (starts with %s) from the Standard keys table on the same page.":
        "انسخ المفتاح العام المطابق (يبدأ بـ %s) من جدول \"المفاتيح القياسية\" في الصفحة نفسها.",
    "XPay confirms payments by sending signed events to this address. It is what marks orders paid. Each mode needs its own endpoint with its own secret.":
        "تؤكد XPay المدفوعات بإرسال أحداث موقَّعة إلى هذا العنوان — وهو ما يُعلّم الطلبات كمدفوعة. كل وضع يحتاج نقطة نهاية خاصة به وسرًّا خاصًّا به.",
    "In the left sidebar, open Developer hub, then the Webhooks tab, and click Add endpoint.":
        "من الشريط الجانبي افتح \"منصة المطورين\"، ثم تبويب \"Webhooks\"، واضغط \"إضافة نقطة نهاية\".",
    "Paste your store's address above into the Endpoint URL field.":
        "ألصق عنوان متجرك أعلاه في حقل \"رابط نقطة النهاية\".",
    "Under \"Select events to listen to\", tick exactly these seven, then click Add endpoint:":
        "تحت \"اختر الأحداث للاستماع إليها\" حدد هذه الأحداث السبعة بالضبط، ثم اضغط \"إضافة نقطة نهاية\":",
    "The Webhook signing secret is shown only once, right after the endpoint is created. Copy the whsec_… value immediately and paste it into the signing secret field here, then save.":
        "يظهر \"مفتاح توقيع Webhook\" مرة واحدة فقط بعد إنشاء نقطة النهاية مباشرة — انسخ قيمة whsec_… فورًا وألصقها في حقل سر التوقيع هنا، ثم احفظ.",
    "XPay log cleared.": "تم مسح سجل XPay.",
    "This order was not paid with XPay.": "لم يتم دفع هذا الطلب عبر XPay.",
    "Session:": "الجلسة:",
    "Payment intent:": "نية الدفع:",
    "Customer:": "العميل:",
    "Attempts: %d": "المحاولات: %d",
    "No log entries for this order. Enable diagnostic logging in the XPay settings to record payment events.":
        "لا توجد إدخالات سجل لهذا الطلب. فعِّل التسجيل التشخيصي في إعدادات XPay لتسجيل أحداث الدفع.",
    "View full log for this order": "عرض السجل الكامل لهذا الطلب",
    "Card": "بطاقة بنكية",
    "ValU": "ڤاليو",
    "Fawry": "فوري",
    "Pay with your Visa, Mastercard or Meeza card.": "ادفع ببطاقة فيزا أو ماستركارد أو ميزة.",
    "Split your payment into installments with ValU.": "قسّم مبلغك إلى أقساط مع ڤاليو.",
    "Get a reference code and pay at any Fawry point or in the app.":
        "احصل على كود مرجعي وادفع في أي منفذ فوري أو من التطبيق.",
    "Order %s": "طلب %s",
    "XPay checkout session %1$s created (attempt %2$d).": "تم إنشاء جلسة دفع XPay \u200f%1$s (المحاولة %2$d).",
    "Accept cards, ValU and more via XPay (Egypt). Customers pay without leaving your store, and each payment method appears as its own option at checkout.":
        "استقبل البطاقات وڤاليو والمزيد عبر XPay (مصر). يدفع العملاء دون مغادرة متجرك، وتظهر كل وسيلة دفع كخيار مستقل في صفحة إتمام الطلب.",
    "Enable/Disable": "تفعيل/تعطيل",
    "Enable XPay": "تفعيل XPay",
    "Title": "العنوان",
    "Payment method name shown to customers at checkout.": "اسم وسيلة الدفع الظاهر للعملاء عند إتمام الشراء.",
    "Description": "الوصف",
    "One sentence under the payment method name.": "جملة واحدة تظهر أسفل اسم وسيلة الدفع.",
    "Pay securely by card or ValU.": "ادفع بأمان بالبطاقة أو ڤاليو.",
    "Mode": "الوضع",
    "Test mode never charges real money. Keys and webhook secrets are separate per mode.":
        "وضع الاختبار لا يخصم أموالًا حقيقية أبدًا. المفاتيح وأسرار الـ webhook منفصلة لكل وضع.",
    "Test": "اختبار",
    "Live": "مباشر",
    "Test secret key": "المفتاح السري للاختبار",
    "A restricted key (rk_test_…) with Checkout Sessions, Refunds and Webhook Endpoints access, from your XPay dashboard → Developers → API keys.":
        "مفتاح مقيّد (rk_test_…) بصلاحية جلسات الدفع والمبالغ المستردة ونقاط اتصال الويب هوك، من لوحة تحكم XPay ← Developers ← API keys.",
    "Test publishable key": "المفتاح القابل للنشر للاختبار",
    "pk_test_… key, used by the secure payment window in the browser.":
        "مفتاح pk_test_…، تستخدمه نافذة الدفع الآمنة في المتصفح.",
    "Test webhook signing secret": "سر توقيع webhook للاختبار",
    "whsec_… secret for a webhook endpoint pointing at %s (events: checkout.session.completed, checkout.session.expired, payment_intent.payment_failed, charge.refunded, refund.failed).":
        "سر whsec_… لنقطة استقبال webhook تشير إلى %s (الأحداث: checkout.session.completed وcheckout.session.expired).",
    "Live secret key": "المفتاح السري المباشر",
    "A restricted key (rk_live_…) with Checkout Sessions, Refunds and Webhook Endpoints access.":
        "مفتاح مقيّد (rk_live_…) بصلاحية جلسات الدفع والمبالغ المستردة ونقاط اتصال الويب هوك.",
    "Live publishable key": "المفتاح القابل للنشر المباشر",
    "pk_live_… key.": "مفتاح pk_live_….",
    "Live webhook signing secret": "سر توقيع webhook المباشر",
    "whsec_… secret for a live-mode webhook endpoint pointing at %s.":
        "سر whsec_… لنقطة استقبال webhook في الوضع المباشر تشير إلى %s.",
    "Checkout display": "طريقة العرض عند إتمام الشراء",
    "Choose how XPay appears on your checkout page.": "اختر كيف يظهر XPay في صفحة إتمام الشراء.",
    "Payment options": "خيارات الدفع",
    "Separate options let shoppers pick their method before the payment window opens, so it opens directly on that method.":
        "الخيارات المنفصلة تتيح للمتسوقين اختيار وسيلتهم قبل فتح نافذة الدفع، فتُفتح مباشرة على تلك الوسيلة.",
    "One XPay option for all methods": "خيار XPay واحد لجميع الوسائل",
    "A separate option per payment method": "خيار منفصل لكل وسيلة دفع",
    "Offer Card (Visa, Mastercard, Meeza) as its own option": "إظهار البطاقة (فيزا، ماستركارد، ميزة) كخيار مستقل",
    "Offer ValU as its own option": "إظهار ڤاليو كخيار مستقل",
    "Offer Fawry as its own option": "إظهار فوري كخيار مستقل",
    "Only tick methods that are enabled for your XPay account. Shoppers who pick a method your account does not have are shown the full XPay window instead, and you get a notice here in admin.":
        "حدّد فقط الوسائل المفعّلة في حساب XPay الخاص بك. المتسوقون الذين يختارون وسيلة غير متاحة في حسابك تظهر لهم نافذة XPay الكاملة بدلًا منها، وستصلك ملاحظة هنا في لوحة التحكم.",
    "Diagnostic logging": "التسجيل التشخيصي",
    "Write redacted diagnostic logs (WooCommerce → Status → Logs, source \"xpay\")":
        "كتابة سجلات تشخيصية بعد إخفاء البيانات الحساسة (WooCommerce ← الحالة ← السجلات، المصدر \"xpay\")",
    "XPay: no API key is saved for the selected mode, so XPay stays hidden at checkout until you add one.":
        "XPay: لا يوجد مفتاح API محفوظ للوضع المحدد، لذلك سيظل XPay مخفيًا عند إتمام الشراء حتى تضيف واحدًا.",
    "XPay: the key in the selected mode is a LIVE key but the gateway is in Test mode. Paste the matching key for the mode you selected.":
        "XPay: المفتاح في الوضع المحدد مفتاح مباشر (LIVE) بينما البوابة في وضع الاختبار. ألصق المفتاح المطابق للوضع الذي اخترته.",
    "XPay: the key in the selected mode is a TEST key but the gateway is in Live mode. Paste the matching key for the mode you selected.":
        "XPay: المفتاح في الوضع المحدد مفتاح اختبار (TEST) بينما البوابة في الوضع المباشر. ألصق المفتاح المطابق للوضع الذي اخترته.",
    "XPay connected (test mode).": "تم الاتصال بـ XPay (وضع الاختبار).",
    "XPay connected (live mode).": "تم الاتصال بـ XPay (الوضع المباشر).",
    "XPay: the API key did not validate. %s": "XPay: تعذّر التحقق من مفتاح API — %s",
    "The payment could not be started. Please try again. Your card has not been charged.":
        "تعذّر بدء عملية الدفع. من فضلك حاول مرة أخرى — لم يتم الخصم من بطاقتك.",
    "XPay session creation failed [%1$s]: %2$s": "فشل إنشاء جلسة XPay \u200f[%1$s]: %2$s",
    "The payment could not be started. Please refresh the page to try again.":
        "تعذّر بدء عملية الدفع. من فضلك حدّث الصفحة وحاول مرة أخرى.",
    "Opening secure payment…": "جارٍ فتح الدفع الآمن…",
    "Taking you to the secure payment page…": "جارٍ نقلك إلى صفحة الدفع الآمنة…",
    "Pay now": "ادفع الآن",
    "Your order is saved. Pay when you are ready.": "تم حفظ طلبك. ادفع متى كنت مستعدًا.",
    "Refund amount is required.": "مبلغ الاسترداد مطلوب.",
    "XPay — %s": "XPay — %s",
    "A dedicated checkout option managed from the main XPay settings.":
        "خيار دفع مخصص يُدار من إعدادات XPay الرئيسية.",
    "XPay: your XPay account does not have %s enabled. Shoppers who picked it were shown the full XPay payment window instead. Enable the method in your XPay dashboard, or untick it under WooCommerce → Settings → Payments → XPay.":
        "XPay: حساب XPay الخاص بك لا يتضمن تفعيل %s. المتسوقون الذين اختاروها ظهرت لهم نافذة دفع XPay الكاملة بدلًا منها. فعِّل الوسيلة من لوحة تحكم XPay، أو ألغِ تحديدها من WooCommerce ← الإعدادات ← المدفوعات ← XPay.",
    "XPay payment confirmed via %1$s. Payment intent: %2$s": "تم تأكيد دفعة XPay عبر %1$s. نية الدفع: %2$s",
    "webhook": "الـ webhook",
    "thank-you page check": "فحص صفحة الشكر",
    "XPay charged %1$s but this order totals %2$s. Review the payment in your XPay dashboard, adjust the order if needed, then complete or refund it manually.":
        "خصم XPay مبلغ %1$s بينما إجمالي هذا الطلب %2$s. راجع الدفعة في لوحة تحكم XPay، وعدّل الطلب إذا لزم، ثم أكمله أو استرد المبلغ يدويًا.",
    "XPay checkout session expired without payment. The order can still be paid through its payment link.": "انتهت صلاحية جلسة الدفع في XPay دون سداد. لا يزال بالإمكان دفع الطلب عبر رابط الدفع الخاص به.",
    "Awaiting payment": "في انتظار الدفع",
    "Order #%1$s · %2$s": "طلب رقم %1$s · %2$s",
    "%1$s × %2$d": "%1$s × %2$d",
    "Total": "الإجمالي",
    "Secured by": "مؤمَّن بواسطة",
    "Usually under a minute. We will email you the moment it is confirmed.":
        "عادةً في أقل من دقيقة. سنرسل لك بريدًا إلكترونيًا فور التأكيد.",
    "Paid": "مدفوع",
    "Confirming payment": "جارٍ تأكيد الدفع",
    "XPay refund of %1$s submitted (%2$s). Reason: %3$s": "تم إرسال استرداد XPay بمبلغ %1$s \u200f(%2$s). السبب: %3$s",
    "XPay accepted the request but did not complete the refund. Check the payment in your XPay dashboard before retrying.":
        "قبل XPay الطلب لكنه لم يُكمل الاسترداد. تحقق من الدفعة في لوحة تحكم XPay قبل إعادة المحاولة.",
    "XPay accepted this refund and is still processing it. Do not submit it again. Check the payment in your XPay dashboard and record the refund here once it completes.":
        "قبل XPay هذا الاسترداد وما زال يعالجه. لا ترسله مرة أخرى — تحقق من الدفعة في لوحة تحكم XPay وسجّل الاسترداد هنا بعد اكتماله.",
    "XPay cannot refund this payment in its current state. Check the payment in your XPay dashboard.":
        "لا يمكن لـ XPay استرداد هذه الدفعة في حالتها الحالية. تحقق من الدفعة في لوحة تحكم XPay.",
    "XPay: WPFunnels is active. Unless you run a WPFunnels Pro upsell flow, shoppers who pay with XPay can land on the cart page instead of the order confirmation. Turn on the WPFunnels safeguard in the XPay settings.":
        "XPay: إضافة WPFunnels مفعّلة. ما لم تكن تستخدم مسار بيع إضافي (Upsell) من WPFunnels Pro، فقد يصل المتسوقون الذين يدفعون عبر XPay إلى صفحة السلة بدلًا من تأكيد الطلب. فعِّل خيار حماية WPFunnels من إعدادات XPay.",
    "Open XPay settings": "فتح إعدادات XPay",
    "Dismiss: I run a WPFunnels Pro upsell flow": "تجاهل — أستخدم مسار بيع إضافي من WPFunnels Pro",
    "WPFunnels compatibility": "توافق WPFunnels",
    "Only relevant when the WPFunnels plugin is active.": "لا يهم هذا الخيار إلا عندما تكون إضافة WPFunnels مفعّلة.",
    "Confirmation page": "صفحة التأكيد",
    "Force the standard order-received page after payment": "فرض صفحة استلام الطلب القياسية بعد الدفع",
    "WPFunnels reroutes the after-payment page into its funnel flow. Without a WPFunnels Pro upsell step, that bounces shoppers to the cart with no confirmation. Turn this on unless you run a working upsell flow. Applies to XPay orders only.":
        "تعيد WPFunnels توجيه صفحة ما بعد الدفع إلى مسارها الخاص. وبدون خطوة بيع إضافي من WPFunnels Pro، يُعاد المتسوقون إلى السلة دون أي تأكيد. فعِّل هذا الخيار ما لم يكن لديك مسار بيع إضافي يعمل فعلًا. يسري على طلبات XPay فقط.",
    "Set up XPay": "إعداد XPay",
    "Not connected": "غير متصل",
    "Three steps · about five minutes": "ثلاث خطوات · نحو خمس دقائق",
    "Connected: Live mode": "متصل — الوضع المباشر",
    "Connected: Test mode": "متصل — وضع الاختبار",
    "Live mode: keys not validated yet": "الوضع المباشر — لم يتم التحقق من المفاتيح بعد",
    "Test mode: keys not validated yet": "وضع الاختبار — لم يتم التحقق من المفاتيح بعد",
    "Open XPay dashboard ↗": "فتح لوحة تحكم XPay ↗",
    "Secret key": "المفتاح السري",
    "Publishable key": "المفتاح القابل للنشر",
    "Validate & save keys": "تحقق واحفظ المفاتيح",
    "Connect the webhook": "اربط الـ webhook",
    "Unlocks after your keys validate: one URL to paste, one secret to copy back":
        "يُفتح بعد التحقق من مفاتيحك — رابط واحد تلصقه وسر واحد تنسخه",
    "Place a test payment": "نفّذ عملية دفع تجريبية",
    "We confirm the whole loop (window, webhook, receipt) end to end":
        "نتأكد من الدورة كاملة — النافذة والـ webhook والإيصال — من البداية إلى النهاية",
    "Nothing goes live until you switch the mode yourself. Card details never touch your server.":
        "لن يعمل أي شيء بشكل مباشر حتى تبدّل الوضع بنفسك. بيانات البطاقات لا تمر على خادمك أبدًا.",
    "Keys validated": "تم التحقق من المفاتيح",
    "Save changes runs a live check against the XPay API": "حفظ التغييرات يجري فحصًا حقيقيًا عبر واجهة XPay",
    "Keys saved, not validated yet": "المفاتيح محفوظة — لم يتم التحقق منها بعد",
    "No keys for the selected mode": "لا توجد مفاتيح للوضع المحدد",
    "Paste them below from your XPay dashboard": "ألصقها أدناه من لوحة تحكم XPay",
    "Webhook not connected": "الـ webhook غير مرتبط",
    "Paste the signing secret below. The webhook is what marks orders paid":
        "ألصق سر التوقيع أدناه — الـ webhook هو ما يحدد الطلبات المدفوعة",
    "Webhook healthy": "الـ webhook يعمل بصحة جيدة",
    "signing secret saved · last event received %s ago": "سر التوقيع محفوظ · آخر حدث وصل منذ %s",
    "View log": "عرض السجل",
    "View logs": "عرض السجلات",
    "Settings saved.": "تم حفظ الإعدادات.",
    "Account details refreshed.": "تم تحديث بيانات الحساب.",
    "Disconnected.": "تم الفصل.",
    "Automatic": "تلقائي",
    "Light": "فاتح",
    "Dark": "داكن",
    "Automatic matches your store's own colours, fonts and corners, and follows the shopper's device for light or dark.":
        "التلقائي يطابق ألوان متجرك وخطوطه وزواياه، ويتبع جهاز المتسوق للوضع الفاتح أو الداكن.",
    "Refreshing…": "جارٍ التحديث…",
    "Connecting…": "جارٍ الاتصال…",
    "Start accepting payments": "ابدأ بقبول المدفوعات",
    "Connect your XPay account to start accepting payments. Setup is automatic, and card details never touch your server.": "اربط حساب XPay لبدء قبول المدفوعات. يتم الإعداد تلقائيًا، ولا تمر بيانات البطاقة بخادمك أبدًا.",
    "Starts in test mode. Nothing goes live until you switch it yourself.": "يبدأ في الوضع التجريبي. لا شيء يصبح فعلياً حتى تبدّله بنفسك.",
    "Connecting needs your site served over HTTPS.": "يتطلب الاتصال أن يعمل موقعك عبر HTTPS.",
    "Connect with XPay in live mode": "الاتصال بـ XPay في الوضع الفعلي",
    "Connect with XPay in test mode": "الاتصال بـ XPay في الوضع التجريبي",
    "Connect your XPay account. Payment methods and order updates are set up automatically.": "اربط حساب XPay. يتم إعداد طرق الدفع وتحديثات الطلبات تلقائيًا.",
    "Your webhook endpoint is set up. Events are sent to: %s.": "نقطة الويب هوك الخاصة بك جاهزة. تُرسل الأحداث إلى: %s.",
    "Webhook events will be sent to: %s.": "ستُرسل أحداث الويب هوك إلى: %s.",
    "XPay: no API key is saved for the selected mode, so XPay stays hidden at checkout until you connect it.": "XPay: لا يوجد مفتاح API محفوظ للوضع المحدد، لذلك يبقى XPay مخفياً في صفحة الدفع حتى تربطه.",
    "XPay: the stored key for the selected mode is a LIVE key but the gateway is in Test mode. Connect the mode you selected from the connection dialog.": "XPay: المفتاح المحفوظ للوضع المحدد مفتاح فعلي بينما البوابة في الوضع التجريبي. اربط الوضع الذي اخترته من نافذة الاتصال.",
    "XPay: the stored key for the selected mode is a TEST key but the gateway is in Live mode. Connect the mode you selected from the connection dialog.": "XPay: المفتاح المحفوظ للوضع المحدد مفتاح تجريبي بينما البوابة في الوضع الفعلي. اربط الوضع الذي اخترته من نافذة الاتصال.",
    "XPay: the stored publishable key does not belong to the mode you selected. Connect this mode again to store a matching pair.": "XPay: المفتاح القابل للنشر المحفوظ لا ينتمي إلى الوضع الذي اخترته. اربط هذا الوضع مرة أخرى لحفظ زوج متطابق.",
    "XPay: the stored secret key was refused. It may have been revoked in your XPay dashboard. Connect again to store a fresh key.": "XPay: رُفض المفتاح السري المحفوظ. ربما أُلغي من لوحة تحكم XPay. اربط مرة أخرى لحفظ مفتاح جديد.",
    "XPay: the stored key cannot act for your account. It looks like a publishable key, which belongs in the browser, not on the server. Connect again to store the right keys.": "XPay: المفتاح المحفوظ لا يستطيع التصرف باسم حسابك. يبدو أنه مفتاح قابل للنشر، ومكانه المتصفح لا الخادم. اربط مرة أخرى لحفظ المفاتيح الصحيحة.",
    "XPay: this key is missing: %s. Connect again from the settings screen to mint a key with everything the plugin needs.": "XPay: هذا المفتاح ينقصه: %s. اربط مرة أخرى من شاشة الإعدادات لإصدار مفتاح يحمل كل ما تحتاجه الإضافة.",
    "XPay: this key cannot manage webhooks, so order confirmations are not connected. Use Connect with XPay on the settings screen to get a key that can, or allow Webhook Endpoints (write) on this key in your XPay dashboard and save again.": "XPay: هذا المفتاح لا يستطيع إدارة الويب هوك، لذلك تأكيدات الطلبات غير متصلة. استخدم الاتصال بـ XPay من شاشة الإعدادات للحصول على مفتاح يستطيع ذلك، أو اسمح بنقاط الويب هوك (كتابة) لهذا المفتاح من لوحة تحكم XPay ثم احفظ مرة أخرى.",
    "XPay: the webhook could not be set up automatically just now. Use Reconfigure webhook in the connection dialog to retry.": "XPay: تعذّر تجهيز الويب هوك تلقائياً الآن. استخدم زر إعادة تجهيز الويب هوك في نافذة الاتصال للمحاولة مرة أخرى.",
    "Accept cards, ValU and Fawry on your own checkout page. Connect signs this store into your XPay account: you approve access on XPay's page, and your keys and order confirmations are set up for you.": "اقبل البطاقات وڤاليو وفوري على صفحة الدفع الخاصة بمتجرك. زر الاتصال يربط هذا المتجر بحساب XPay الخاص بك: توافق على الوصول من صفحة XPay، وتُحفظ مفاتيحك وتُجهَّز تأكيدات الطلبات تلقائياً.",
    "Connect with XPay": "الاتصال بـ XPay",
    "Connect live account": "اتصال بالحساب الفعلي",
    "Connect test account": "اتصال بالحساب التجريبي",
    "You approve access on XPay's own page. Keys and the webhook are set up for you when you come back.": "توافق على الوصول من صفحة XPay نفسها. عند عودتك تكون المفاتيح والويب هوك قد جُهِّزت لك.",
    "The connection could not be started. Check that your store can reach XPay and try again.": "تعذّر بدء الاتصال. تأكد من أن متجرك يمكنه الوصول إلى XPay ثم حاول مرة أخرى.",
    "XPay: your WordPress account cannot manage store settings, so the connection was not applied. Sign in as a store manager and connect again.": "XPay: حساب ووردبريس الخاص بك لا يستطيع إدارة إعدادات المتجر، لذلك لم يُطبَّق الاتصال. سجّل الدخول كمدير متجر ثم اتصل مرة أخرى.",
    "XPay: this connection link is stale or was already used. Nothing changed. Start again from the Connect button.": "XPay: رابط الاتصال هذا قديم أو استُخدم من قبل. لم يتغير شيء. ابدأ من جديد من زر الاتصال.",
    "XPay connected.": "تم الاتصال بـ XPay.",
    "XPay: the connection was not completed. %s": "XPay: لم يكتمل الاتصال. %s",
    "XPay: the connection was canceled before it finished. Nothing changed. To try again, click Connect.": "XPay: أُلغي الاتصال قبل اكتماله. لم يتغير شيء. للمحاولة مرة أخرى اضغط زر الاتصال.",
    "XPay: the connection could not be completed. Nothing changed. Try again in a moment. If it keeps failing, the WooCommerce logs (source \"xpay\") carry the details.": "XPay: تعذّر إكمال الاتصال. لم يتغير شيء. حاول مرة أخرى بعد قليل. إذا استمر الفشل فستجد التفاصيل في سجلات ووكومرس (المصدر \"xpay\").",
    "Could not reach the store. Reload the page and try again.": "تعذر الوصول إلى المتجر. أعد تحميل الصفحة وحاول مرة أخرى.",
    "Disconnect this mode? Its keys are removed from this store and its webhook endpoint is deleted at XPay. Payments in this mode stop until you connect again.":
        "فصل هذا الوضع؟ ستتم إزالة مفاتيحه من هذا المتجر وحذف نقطة اتصال الويب هوك الخاصة به في XPay. تتوقف المدفوعات في هذا الوضع حتى تتصل مرة أخرى.",
    "Live mode": "الوضع الفعلي",
    "Test mode": "الوضع التجريبي",
    "Open XPay dashboard": "افتح لوحة تحكم XPay",
    "Get started with XPay": "ابدأ مع XPay",
    "Accept cards, ValU and Fawry on your own checkout page. Connect your XPay account with its API keys: one save validates the keys and sets up order confirmations, with nothing else to configure.":
        "استقبل البطاقات وڤاليو وفوري في صفحة إتمام الطلب الخاصة بك. اربط حساب XPay بمفاتيح API الخاصة به: حفظة واحدة تتحقق من المفاتيح وتُعِدّ تأكيدات الطلبات، دون أي إعداد آخر.",
    "Connect your XPay account": "اربط حساب XPay الخاص بك",
    "Account status": "حالة الحساب",
    "Account": "الحساب",
    "Connected": "متصل",
    "Disconnected": "غير متصل",
    "Payments": "المدفوعات",
    "Awaiting activation": "في انتظار التفعيل",
    "Enabled": "مفعّل",
    "Disabled": "معطّل",
    "Configured": "مُعدّ",
    "Not configured": "غير مُعدّ",
    "Refresh": "تحديث",
    "Connection actions": "إجراءات الاتصال",
    "Configure connection": "إعداد الاتصال",
    "Refresh account details": "تحديث بيانات الحساب",
    "Disconnect": "فصل",
    "Settings": "الإعدادات",
    "When off, no XPay option appears at checkout.": "عند الإيقاف، لا يظهر أي خيار XPay في صفحة إتمام الطلب.",
    "Enable test mode": "تفعيل الوضع التجريبي",
    "Test mode never charges real money.": "الوضع التجريبي لا يخصم أموالًا حقيقية أبدًا.",
    "Shown when the checkout falls back to a single combined XPay option; the per-method options name themselves.":
        "يظهر عندما تعود صفحة إتمام الطلب إلى خيار XPay واحد مجمّع؛ خيارات وسائل الدفع المنفصلة تسمّي نفسها.",
    "One sentence under that combined option.": "جملة واحدة أسفل ذلك الخيار المجمّع.",
    "How XPay's payment fields look inside your checkout.": "كيف تبدو حقول الدفع من XPay داخل صفحة إتمام الطلب.",
    "Write redacted diagnostic logs to WooCommerce → Status → Logs (source \"xpay\"). Failures are always recorded either way.":
        "كتابة سجلات تشخيصية منقّحة إلى WooCommerce ← الحالة ← السجلات (المصدر \"xpay\"). تُسجّل الأعطال دائمًا في كل الأحوال.",
    "WPFunnels: force the standard confirmation page": "WPFunnels: فرض صفحة التأكيد القياسية",
    "Only relevant when the WPFunnels plugin is active. Keeps shoppers on the normal order-received page after paying, unless you run a working upsell flow.":
        "مهم فقط عندما تكون إضافة WPFunnels نشطة. يُبقي المتسوقين على صفحة استلام الطلب العادية بعد الدفع، ما لم تكن لديك مسار بيع إضافي يعمل.",
    "Guides and troubleshooting live at <a href=\"%s\" target=\"_blank\" rel=\"noopener noreferrer\">docs.xpay.app</a>.":
        "الأدلة وحل المشكلات على <a href=\"%s\" target=\"_blank\" rel=\"noopener noreferrer\">docs.xpay.app</a>.",
    "XPay account and webhooks": "حساب XPay ونقاط الويب هوك",
    "XPay account & webhooks": "حساب XPay ونقاط الويب هوك",
    "Keys live in your XPay dashboard under Developers, then API keys. Create a restricted key allowing Checkout Sessions, Refunds and Webhook Endpoints. Saving validates the keys and sets up the webhook endpoint for you.":
        "المفاتيح في لوحة تحكم XPay ضمن المطورون ثم مفاتيح API. أنشئ مفتاحًا مقيدًا يسمح بجلسات الدفع والمبالغ المستردة ونقاط اتصال الويب هوك. الحفظ يتحقق من المفاتيح ويُعِدّ نقطة اتصال الويب هوك لك.",
    "Cancel": "إلغاء",
    "Save keys": "حفظ المفاتيح",
    "Managed by the plugin": "تديره الإضافة",
    "Manual secret saved": "سر يدوي محفوظ",
    "Webhook details": "تفاصيل الويب هوك",
    "Reconfigure webhook": "إعادة إعداد الويب هوك",
    "Just reconfigured. Give it a minute before trying again.": "تمت إعادة الإعداد للتو. انتظر دقيقة قبل المحاولة مرة أخرى.",
    "Save this mode's keys first.": "احفظ مفاتيح هذا الوضع أولًا.",
    "Webhook reconfigured. The signing secret was stored for you.": "تمت إعادة إعداد الويب هوك. تم حفظ سر التوقيع لك.",
    "XPay: this key cannot manage webhooks, so the webhook was not set up automatically. Either allow Webhook Endpoints (write) on the key in your XPay dashboard and save again, or create the endpoint there yourself and paste its signing secret below.":
        "XPay: هذا المفتاح لا يمكنه إدارة الويب هوك، لذلك لم يتم إعداد الويب هوك تلقائيًا. إما أن تسمح بصلاحية Webhook Endpoints (write) للمفتاح في لوحة تحكم XPay ثم تحفظ مرة أخرى، أو أنشئ نقطة الاتصال هناك بنفسك والصق سر التوقيع الخاص بها أدناه.",
    "XPay: webhook set up automatically. Order confirmations are connected, with nothing to paste.":
        "XPay: تم إعداد الويب هوك تلقائيًا. تأكيدات الطلبات متصلة الآن، دون الحاجة إلى لصق أي شيء.",
    "XPay: the webhook could not be set up automatically just now. Save again to retry, or create the endpoint in your XPay dashboard and paste its signing secret below.":
        "XPay: تعذر إعداد الويب هوك تلقائيًا الآن. احفظ مرة أخرى لإعادة المحاولة، أو أنشئ نقطة الاتصال في لوحة تحكم XPay والصق سر التوقيع الخاص بها أدناه.",
    "The most recent event, received %s, was processed successfully.":
        "أحدث حدث، المستلم في %s، تمت معالجته بنجاح.",
    "No webhook events received yet.": "لم تُستلم أحداث ويب هوك بعد.",
    "The most recent event, received %1$s, could not be processed. %2$s The last event to process successfully arrived %3$s.":
        "أحدث حدث، المستلم في %1$s، تعذرت معالجته. %2$s آخر حدث تمت معالجته بنجاح وصل في %3$s.",
    "The most recent event, received %1$s, could not be processed. %2$s No event has processed successfully since monitoring began at %3$s.":
        "أحدث حدث، المستلم في %1$s، تعذرت معالجته. %2$s لم تتم معالجة أي حدث بنجاح منذ بدء المراقبة في %3$s.",
    "Webhook waiting for its first event": "الـ webhook في انتظار أول حدث",
    "signing secret saved · send a test event from your XPay dashboard":
        "سر التوقيع محفوظ · أرسل حدثًا تجريبيًا من لوحة تحكم XPay",
    "Payment confirmed end-to-end": "تم تأكيد الدفع من البداية إلى النهاية",
    "order #%1$s · %2$s": "طلب رقم %1$s · %2$s",
    "View order": "عرض الطلب",
    "No payment yet": "لا توجد مدفوعات بعد",
    "Place a test order to prove the whole loop": "نفّذ طلبًا تجريبيًا لإثبات الدورة كاملة",
    "Ready to go live": "جاهز للانطلاق المباشر",
    "paste your live keys, create a live webhook endpoint, switch the mode":
        "ألصق مفاتيحك المباشرة، وأنشئ نقطة webhook مباشرة، وبدّل الوضع",
    "Go live": "انطلق مباشرة",
    "Account & keys": "الحساب والمفاتيح",
    "Each mode keeps its own keys. Switching never overwrites anything.":
        "كل وضع يحتفظ بمفاتيحه — التبديل لا يستبدل شيئًا أبدًا.",
    "Webhook": "Webhook",
    "XPay confirms orders through this endpoint. It is what marks orders paid.":
        "يؤكد XPay الطلبات عبر هذه النقطة — وهي ما يحدد الطلبات المدفوعة.",
    "Endpoint URL": "رابط النقطة",
    "Copy": "نسخ",
    "Signing secret": "سر التوقيع",
    "Health": "الحالة",
    "Healthy: last event received %s ago": "بصحة جيدة — آخر حدث وصل منذ %s",
    "No events received yet. Send a test event from your XPay dashboard":
        "لم تصل أحداث بعد — أرسل حدثًا تجريبيًا من لوحة تحكم XPay",
    "Signing secret missing for the selected mode": "سر التوقيع مفقود للوضع المحدد",
    "View in XPay Log": "عرض في سجل XPay",
    "Checkout appearance": "مظهر إتمام الشراء",
    "Separate options open the payment window directly on the shopper’s method.":
        "الخيارات المنفصلة تفتح نافذة الدفع مباشرة على وسيلة المتسوق.",
    "One XPay option": "خيار XPay واحد",
    "Separate options": "خيارات منفصلة",
    "Title at checkout": "العنوان عند إتمام الشراء",
    "WPFunnels safeguard": "حماية WPFunnels",
    "Save changes": "حفظ التغييرات",
    "Not set": "غير محدد",
    "Reveal": "إظهار",
    "Replace": "استبدال",
    "Add": "إضافة",
    "Logging on": "التسجيل مفعّل",
    "Logging off": "التسجيل متوقف",

    # ValU wallet number, asked for on the checkout page when nothing the
    # order holds can be sent as the wallet the payment will spend.
    "Mobile number for your ValU wallet":
        "رقم الموبايل الخاص بمحفظة ڤاليو",
    "ValU pays from the wallet registered to your mobile number, and the number on this order is not an Egyptian or Jordanian mobile. Enter the one your ValU account uses.":
        "تدفع ڤاليو من المحفظة المسجّلة برقم موبايلك، والرقم الموجود على هذا الطلب ليس رقم موبايل مصري أو أردني. أدخل الرقم المسجّل بحساب ڤاليو.",
    "ValU pays from the wallet registered to your mobile number. Enter the Egyptian or Jordanian mobile your ValU account uses.":
        "تدفع ڤاليو من المحفظة المسجّلة برقم موبايلك. أدخل رقم الموبايل المصري أو الأردني المسجّل بحساب ڤاليو.",
    "Enter the mobile number registered to your ValU wallet.":
        "أدخل رقم الموبايل المسجّل بمحفظة ڤاليو.",
    "That is not an Egyptian or Jordanian mobile number. Check the number registered to your ValU wallet and try again.":
        "هذا ليس رقم موبايل مصري أو أردني. راجع الرقم المسجّل بمحفظة ڤاليو وحاول مرة أخرى.",
    # Store API schema descriptions for the wallet-number verdict. Not
    # shopper-facing, but the generator translates every msgid or fails.
    "Whether the shopper must be asked for a ValU wallet number.":
        "ما إذا كان يجب سؤال المتسوق عن رقم محفظة ڤاليو.",
    "Whether the order already carries a phone number of any kind.":
        "ما إذا كان الطلب يحمل بالفعل رقم هاتف من أي نوع.",

    "Shopper gave %s as their ValU wallet number. The billing phone on this order was left as they entered it.":
        "أعطى المتسوق %s كرقم محفظة ڤاليو. تُرك رقم هاتف الفوترة في هذا الطلب كما أدخله.",
    # ── Order-panel refundable balance (admin order screen) ──────────
    "Level": "المستوى",
    "checking…": "جارٍ التحقق…",
    "could not check just now": "تعذّر التحقق الآن",
    "Nothing left to refund at XPay.": "لم يتبقَّ شيء لاسترداده لدى XPay.",
    "XPay has already refunded this payment in full, so it cannot refund any more. If WooCommerce disagrees, a refund was issued in the XPay dashboard and this order has not caught up yet.":
        "استرد XPay هذه الدفعة بالكامل بالفعل، فلا يمكنه استرداد المزيد. إذا كان WooCommerce يعرض غير ذلك، فقد صدر استرداد من لوحة تحكم XPay ولم يلحق به هذا الطلب بعد.",
    "Settled to you: %s": "المُسوّى لحسابك: %s",
    "shown to the customer as %s": "يظهر للعميل كـ %s",
    "rate %s": "بسعر صرف %s",
    "Refund amount in %s": "مبلغ الاسترداد بعملة %s",
    "XPay refunds in %1$s at the rate locked when the customer paid: 1 %2$s = %3$s %1$s. The %1$s figure is what actually moves.":
        "يسترد XPay بعملة %1$s بسعر الصرف المثبَّت وقت دفع العميل: 1 %2$s = %3$s %1$s. مبلغ %1$s هو ما يتحرك فعليًا.",
    "This order is priced in %1$s and XPay settles in %2$s. You can refund it in full from here. For part of it, use your XPay dashboard: the refund is recorded on this order automatically, usually within a minute.":
        "هذا الطلب مسعَّر بعملة %1$s بينما يسوّي XPay بعملة %2$s. يمكنك استرداده بالكامل من هنا. أما الاسترداد الجزئي فمن لوحة تحكم XPay، ويُسجَّل على هذا الطلب تلقائيًا خلال دقيقة عادةً.",
    "Refundable at XPay:": "القابل للاسترداد لدى XPay:",

    # ── Key-field validation messages ────────────────────────────────
    "That does not look like an XPay key. They start with rk_, sk_ or pk_.":
        "هذا لا يبدو مفتاح XPay. المفاتيح تبدأ بـ rk_ أو sk_ أو pk_.",
    "That is a secret key. This field takes the publishable key (pk_…).":
        "هذا مفتاح سري. هذا الحقل يستقبل المفتاح القابل للنشر (pk_…).",
    "That is a publishable key. This field takes the secret key (rk_…).":
        "هذا مفتاح قابل للنشر. هذا الحقل يستقبل المفتاح السري (rk_…).",
    "That is a test key, and this is the live field. Live keys contain _live_.":
        "هذا مفتاح تجريبي، وهذا حقل الوضع الفعلي. المفاتيح الفعلية تحتوي على _live_.",
    "That is a LIVE key, and this is the test field. Test keys contain _test_.":
        "هذا مفتاح فعلي، وهذا حقل الوضع التجريبي. المفاتيح التجريبية تحتوي على _test_.",

    # ── Guided setup / status rows ───────────────────────────────────
    "Full in WooCommerce; partial from your XPay dashboard":
        "الاسترداد الكامل من WooCommerce، والجزئي من لوحة تحكم XPay",
    "See all settings": "عرض كل الإعدادات",
    "Take your first payment": "استقبل أول دفعة",
    "XPay is switched off, so it is not on your checkout yet. Turn it on, save, then place one order.":
        "XPay متوقف حاليًا، فلا يظهر في صفحة الدفع بعد. فعِّله واحفظ، ثم أنشئ طلبًا واحدًا.",
    "One order through your own checkout proves the whole loop: the XPay window, the signed webhook, and the receipt.":
        "طلب واحد عبر صفحة الدفع في متجرك يثبت الدورة كاملة: نافذة XPay، وإشعار webhook الموقَّع، والإيصال.",
    "Save": "حفظ",
    "Open your store ↗": "افتح متجرك ↗",
    "Back to the setup guide": "العودة إلى دليل الإعداد",
    "Webhook failing": "إشعارات webhook تفشل",
    "%1$s (last refused %2$s ago)": "%1$s (آخر رفض منذ %2$s)",
    "first payment on this mode received %s ago": "وصلت أول دفعة في هذا الوضع منذ %s",
    "this store has taken a payment through XPay": "استقبل هذا المتجر دفعة عبر XPay",
    "Take one live payment to prove the whole loop": "استقبل دفعة فعلية واحدة لإثبات الدورة كاملة",
    "Failing: %1$s (last refused %2$s ago)": "فشل: %1$s (آخر رفض منذ %2$s)",
    "Validated against the XPay API": "تم التحقق منها عبر XPay API",
    "Connected: events are arriving from XPay": "متصل: الأحداث تصل من XPay",
    "Done: a payment has completed end to end on this store": "تم: اكتملت دفعة من البداية للنهاية على هذا المتجر",

    # ── Checkout fee + confirm-flow strings ──────────────────────────
    "A payment processing fee applies. You will see the exact amount before you pay.":
        "تُطبَّق رسوم معالجة دفع. سترى المبلغ بالضبط قبل الدفع.",
    "Includes a %1$s payment processing fee. Total to pay: %2$s":
        "يشمل رسوم معالجة دفع %1$s. الإجمالي المطلوب: %2$s",
    "Your basket is empty, so there is nothing to pay for. Add something to it and try again.":
        "سلة مشترياتك فارغة، فلا يوجد ما تدفع مقابله. أضف شيئًا إليها وحاول مرة أخرى.",
    "This payment is taking longer than expected. Do not pay again. Check your order status or your XPay app before retrying, and contact us if you are unsure.":
        "تستغرق هذه الدفعة وقتًا أطول من المتوقع. لا تدفع مرة أخرى. تحقق من حالة طلبك أو من تطبيق XPay قبل إعادة المحاولة، وتواصل معنا إذا لم تكن متأكدًا.",
    "Confirming your payment. Please do not close this page.":
        "جارٍ تأكيد دفعتك. برجاء عدم إغلاق هذه الصفحة.",
    "Payment confirmed. Taking you to your order.": "تم تأكيد الدفع. جارٍ نقلك إلى طلبك.",
    "Taking you to the payment page.": "جارٍ نقلك إلى صفحة الدفع.",

    # ── Settings save + process_payment messages ─────────────────────
    "whsec_… secret for a webhook endpoint pointing at %s (events: checkout.session.completed, checkout.session.expired, checkout.session.async_payment_succeeded, checkout.session.async_payment_failed, payment_intent.payment_failed, charge.refunded, refund.failed).":
        "سر whsec_… لنقطة webhook تشير إلى %s (الأحداث: checkout.session.completed وcheckout.session.expired وcheckout.session.async_payment_succeeded وcheckout.session.async_payment_failed وpayment_intent.payment_failed وcharge.refunded وrefund.failed).",
    "XPay: no publishable key is saved for the selected mode. The payment fields cannot load without it.":
        "XPay: لا يوجد مفتاح قابل للنشر محفوظ للوضع المحدد. لا يمكن تحميل حقول الدفع بدونه.",
    "XPay: the publishable key does not belong to the mode you selected. Paste the matching pair from your XPay dashboard.":
        "XPay: المفتاح القابل للنشر لا يخص الوضع الذي اخترته. الصق الزوج المطابق من لوحة تحكم XPay.",
    "XPay: settings saved. XPay could not be reached just now, so the connection is unconfirmed. Reload this page in a moment to check it.":
        "XPay: تم حفظ الإعدادات. تعذّر الوصول إلى XPay الآن، فالاتصال غير مؤكد. أعد تحميل هذه الصفحة بعد قليل للتحقق.",
    "XPay: that secret key was refused. Copy it again from your XPay dashboard. A key can only be revealed with a 6-digit code, so it is easy to paste a partial one.":
        "XPay: رُفض هذا المفتاح السري. انسخه مرة أخرى من لوحة تحكم XPay. لا يظهر المفتاح إلا برمز من 6 أرقام، فمن السهل لصق مفتاح ناقص.",
    "XPay: this key cannot take payments. In your XPay dashboard, edit the restricted key and allow Checkout Sessions, then save again.":
        "XPay: هذا المفتاح لا يستطيع استقبال مدفوعات. في لوحة تحكم XPay، عدِّل المفتاح المقيَّد واسمح بـ Checkout Sessions، ثم احفظ مرة أخرى.",
    "XPay could not be reached [%1$s]: %2$s": "تعذّر الوصول إلى XPay [%1$s]: %2$s",
    "This payment has already been used for another order. Please refresh the checkout and try again. Your card has not been charged twice.":
        "استُخدمت هذه الدفعة بالفعل لطلب آخر. برجاء تحديث صفحة الدفع والمحاولة مرة أخرى. لم يُخصم من بطاقتك مرتين.",
    "XPay: the payment session this order tried to use is already claimed by another order. Nothing was charged for this one.":
        "XPay: جلسة الدفع التي حاول هذا الطلب استخدامها محجوزة بالفعل لطلب آخر. لم يُخصم شيء لهذا الطلب.",
    "XPay: automatic cancellation held back. A payment session for this order is still open at XPay and its result has not arrived yet. WooCommerce will cancel the order after %s if it is still unpaid.":
        "XPay: تم تأجيل الإلغاء التلقائي. لا تزال جلسة دفع لهذا الطلب مفتوحة لدى XPay ولم تصل نتيجتها بعد. سيلغي WooCommerce الطلب بعد %s إذا ظل غير مدفوع.",
    "Payment processing fee": "رسوم معالجة الدفع",

    # ── Paid-after-cancel park ───────────────────────────────────────
    "XPay took a payment for this order after it had already been cancelled.":
        "استقبل XPay دفعة لهذا الطلب بعد أن كان قد أُلغي بالفعل.",
    "XPay took %s for this order after it had already been cancelled.":
        "استقبل XPay مبلغ %s لهذا الطلب بعد أن كان قد أُلغي بالفعل.",
    "The order is on hold so nothing ships automatically. Either complete it, or refund the payment from this order.":
        "الطلب قيد الانتظار فلا يُشحن شيء تلقائيًا. إما أن تكمله، أو تسترد الدفعة من هذا الطلب.",

    # ── Deferred payments (Fawry references) ─────────────────────────
    "XPay: the shopper completed checkout with a payment reference to pay later, for example a Fawry code. No money has been received yet. XPay confirms automatically when the reference is paid or fails, and this order will update on its own. Do not ship before that confirmation.":
        "XPay: أكمل المتسوق الطلب بكود دفع يُسدَّد لاحقًا، مثل كود فوري. لم يُستلم أي مبلغ بعد. يؤكد XPay تلقائيًا عند سداد الكود أو فشله، وسيتحدّث هذا الطلب من تلقاء نفسه. لا تشحن قبل هذا التأكيد.",
    "Reason: %s": "السبب: %s",
    "XPay: the payment reference for this order was not paid. No money was received. The order can still be paid through its payment link.":
        "XPay: لم يُسدَّد كود الدفع الخاص بهذا الطلب. لم يُستلم أي مبلغ. لا يزال بالإمكان دفع الطلب عبر رابط الدفع الخاص به.",
    "Pay for this order": "ادفع لهذا الطلب",

    # ── Refund result wording ────────────────────────────────────────
    "XPay refunded %3$s, which it reports to the customer as %2$s. You asked for %1$s. The difference is rounding between the two currencies; the settled amount is the one that moved.":
        "استرد XPay مبلغ %3$s، ويعرضه للعميل كـ %2$s. أنت طلبت %1$s. الفرق تقريب بين العملتين، والمبلغ المُسوّى هو ما تحرك فعليًا.",
    "XPay refunded the remaining balance, which it reports to the customer as %2$s. You asked for %1$s. The difference is rounding between the two currencies.":
        "استرد XPay الرصيد المتبقي، ويعرضه للعميل كـ %2$s. أنت طلبت %1$s. الفرق تقريب بين العملتين.",
    "This order is not in EGP, and XPay reads refund amounts as EGP, so a part-refund from here would move the wrong amount. Refund the full order instead, which works, or issue the part-refund from your XPay dashboard. A dashboard refund is recorded here automatically.":
        "هذا الطلب ليس بالجنيه المصري، وXPay يقرأ مبالغ الاسترداد بالجنيه المصري، فالاسترداد الجزئي من هنا سيحرّك مبلغًا خاطئًا. استرد الطلب بالكامل من هنا، وهذا يعمل، أو نفّذ الاسترداد الجزئي من لوحة تحكم XPay. يُسجَّل استرداد اللوحة هنا تلقائيًا.",

    # ── Webhook health failure reasons ───────────────────────────────
    "No signing secret is saved for this mode, so deliveries cannot be verified.":
        "لا يوجد سر توقيع محفوظ لهذا الوضع، فلا يمكن التحقق من الإشعارات الواردة.",
    "The signature did not match. The signing secret saved here is probably not the one XPay is signing with.":
        "التوقيع غير مطابق. الغالب أن سر التوقيع المحفوظ هنا ليس هو الذي يوقّع به XPay.",
    "Deliveries are arriving without a signature header. Check that the endpoint URL in your XPay dashboard points at this store.":
        "الإشعارات تصل بدون ترويسة توقيع. تحقق من أن رابط النقطة في لوحة تحكم XPay يشير إلى هذا المتجر.",
    "The delivery was too old to accept. Check that this server's clock is correct.":
        "الإشعار أقدم من أن يُقبل. تحقق من صحة ساعة هذا الخادم.",
    "The delivery was not in the shape this plugin expects.":
        "الإشعار ليس بالشكل الذي تتوقعه هذه الإضافة.",
    "The delivery was verified but could not be applied to an order. The log has the detail.":
        "تم التحقق من الإشعار لكن تعذّر تطبيقه على طلب. التفاصيل في السجل.",
    # ── GET /account key validation ──────────────────────────────────
    "XPay: that is not a key that can act for your account. Paste the secret (sk_) or restricted (rk_) key from your XPay dashboard, not the publishable one.":
        "XPay: هذا ليس مفتاحًا يمكنه التصرف باسم حسابك. الصق المفتاح السري (sk_) أو المقيَّد (rk_) من لوحة تحكم XPay، وليس المفتاح القابل للنشر.",
    "Checkout Sessions (write)": "جلسات الدفع (كتابة)",
    "Refunds (write)": "الاستردادات (كتابة)",
    "XPay: this restricted key is missing: %s. In your XPay dashboard, edit the key to allow them, then save again.":
        "XPay: هذا المفتاح المقيَّد ينقصه: %s. في لوحة تحكم XPay، عدِّل المفتاح للسماح بها، ثم احفظ مرة أخرى.",
    "XPay connected (live mode). Your account is not activated for live payments yet, so payments stay off until XPay activates it. Test mode works fully in the meantime.":
        "تم توصيل XPay (الوضع الفعلي). حسابك غير مفعَّل للمدفوعات الفعلية بعد، فستظل المدفوعات متوقفة حتى يفعّله XPay. الوضع التجريبي يعمل بالكامل في الوقت الحالي.",
    # ── Deferred flow: repricing one session ─────────────────────────
    "XPay payment session updated to %s after the order total changed. The shopper keeps the same payment session.":
        "تم تحديث جلسة دفع XPay إلى %s بعد تغيّر إجمالي الطلب. يحتفظ المتسوق بنفس جلسة الدفع.",
    # ── Order-pay page ───────────────────────────────────────────────
    "The payment form could not load. Reload the page to try again.":
        "تعذّر تحميل نموذج الدفع. أعد تحميل الصفحة للمحاولة مرة أخرى.",
}

# The one plural entry: msgid "%d item" / msgid_plural "%d items".
PLURAL = {
    "The shopper made %d payment attempt that was declined.": [
        "لم يقم المتسوق بأي محاولة دفع مرفوضة.",                    # n == 0
        "قام المتسوق بمحاولة دفع واحدة ورُفضت.",                    # n == 1
        "قام المتسوق بمحاولتي دفع ورُفضتا.",                        # n == 2
        "قام المتسوق بـ %d محاولات دفع ورُفضت.",                    # 3..10
        "قام المتسوق بـ %d محاولة دفع ورُفضت.",                     # 11..99
        "قام المتسوق بـ %d محاولة دفع ورُفضت.",                     # 100+
    ],
    "%d item": [
        "لا منتجات",       # n == 0
        "منتج واحد",       # n == 1
        "منتجان",           # n == 2
        "%d منتجات",        # 3..10
        "%d منتجًا",        # 11..99
        "%d منتج",          # 100+
    ],
}


def unquote(lines):
    out = ""
    for ln in lines:
        m = re.match(r'^"(.*)"$', ln.strip())
        if m is None:
            raise SystemExit(f"bad string line: {ln!r}")
        out += m.group(1)
    return out.replace('\\"', '"').replace("\\n", "\n")


def quote(s):
    return '"' + s.replace('"', '\\"').replace("\n", "\\n") + '"'


entries = []  # (msgid_raw_lines, msgid_plural_raw_lines or None, comments)
with open(POT, encoding="utf-8") as f:
    lines = f.read().splitlines()

i = 0
cur_comments, cur_id, cur_plural, state = [], None, None, None
while i < len(lines):
    ln = lines[i]
    if ln.startswith("#"):
        cur_comments.append(ln)
        state = None
    elif ln.startswith("msgid_plural "):
        cur_plural = [ln[len("msgid_plural "):]]
        state = "plural"
    elif ln.startswith("msgid "):
        cur_id = [ln[len("msgid "):]]
        state = "id"
    elif ln.startswith("msgstr"):
        state = "str"
    elif ln.startswith('"'):
        if state == "id":
            cur_id.append(ln)
        elif state == "plural":
            cur_plural.append(ln)
    elif ln.strip() == "":
        if cur_id is not None:
            entries.append((cur_comments, cur_id, cur_plural))
        cur_comments, cur_id, cur_plural, state = [], None, None, None
    i += 1
if cur_id is not None:
    entries.append((cur_comments, cur_id, cur_plural))

missing = []
out = []
out.append('msgid ""')
out.append('msgstr ""')
out.append('"Project-Id-Version: XPay for WooCommerce\\n"')
out.append('"MIME-Version: 1.0\\n"')
out.append('"Content-Type: text/plain; charset=UTF-8\\n"')
out.append('"Content-Transfer-Encoding: 8bit\\n"')
out.append('"Language: ar\\n"')
out.append('"Plural-Forms: nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : "')
out.append('"n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);\\n"')
out.append("")

for comments, id_lines, plural_lines in entries:
    msgid = unquote(id_lines)
    if msgid == "":
        continue
    for c in comments:
        out.append(c)
    out.append("msgid " + "\n".join(id_lines))
    if plural_lines is not None:
        msgid_plural = unquote(plural_lines)
        out.append("msgid_plural " + "\n".join(plural_lines))
        forms = PLURAL.get(msgid)
        if forms is None:
            missing.append(msgid + " (plural)")
            forms = [""] * 6
        for n, form in enumerate(forms):
            out.append(f"msgstr[{n}] " + quote(form))
    else:
        tr = AR.get(msgid)
        if tr is None:
            missing.append(msgid)
            tr = ""
        out.append("msgstr " + quote(tr))
    out.append("")

if missing:
    print("MISSING TRANSLATIONS:", file=sys.stderr)
    for m in missing:
        print("  - " + m, file=sys.stderr)
    sys.exit(1)

with open(OUT, "w", encoding="utf-8") as f:
    f.write("\n".join(out).rstrip() + "\n")
print(f"wrote {OUT}: {sum(1 for _, i2, _ in entries if unquote(i2))} strings translated")
