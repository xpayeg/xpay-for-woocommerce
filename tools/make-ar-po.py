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
    "XPay for WooCommerce": "XPay لـ WooCommerce",
    "https://xpay.app/": "https://xpay.app/",
    "Accept payments on your WooCommerce store via XPay (Egypt): cards, valU and more, in a secure on-site checkout.":
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
    "Cards, valU and Fawry open in a secure XPay window over your checkout. Shoppers never leave your site, and orders are confirmed only by signed webhooks.":
        "تُفتح مدفوعات البطاقات وڤاليو وفوري في نافذة XPay آمنة فوق صفحة الدفع لديك — لا يغادر المتسوقون موقعك أبدًا، ولا تُؤكد الطلبات إلا عبر إشعارات webhook موقَّعة.",
    "Payment methods": "طرق الدفع",
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
    "Under \"Select events to listen to\", tick exactly these five, then click Add endpoint:":
        "تحت \"اختر الأحداث للاستماع إليها\" حدد هذه الأحداث الخمسة بالضبط، ثم اضغط \"إضافة نقطة نهاية\":",
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
    "valU": "ڤاليو",
    "Fawry": "فوري",
    "Pay with your Visa, Mastercard or Meeza card.": "ادفع ببطاقة فيزا أو ماستركارد أو ميزة.",
    "Split your payment into installments with valU.": "قسّم مبلغك إلى أقساط مع ڤاليو.",
    "Get a reference code and pay at any Fawry point or in the app.":
        "احصل على كود مرجعي وادفع في أي منفذ فوري أو من التطبيق.",
    "Order %s": "طلب %s",
    "XPay checkout session %1$s created (attempt %2$d).": "تم إنشاء جلسة دفع XPay ‏%1$s (المحاولة %2$d).",
    "Accept cards, valU and more via XPay (Egypt). Customers pay in a secure XPay window without leaving your store.":
        "استقبل البطاقات وڤاليو والمزيد عبر XPay (مصر). يدفع العملاء في نافذة XPay آمنة دون مغادرة متجرك.",
    "Enable/Disable": "تفعيل/تعطيل",
    "Enable XPay": "تفعيل XPay",
    "Title": "العنوان",
    "Payment method name shown to customers at checkout.": "اسم وسيلة الدفع الظاهر للعملاء عند إتمام الشراء.",
    "Description": "الوصف",
    "One sentence under the payment method name.": "جملة واحدة تظهر أسفل اسم وسيلة الدفع.",
    "Pay securely by card or valU.": "ادفع بأمان بالبطاقة أو ڤاليو.",
    "Mode": "الوضع",
    "Test mode never charges real money. Keys and webhook secrets are separate per mode.":
        "وضع الاختبار لا يخصم أموالًا حقيقية أبدًا. المفاتيح وأسرار الـ webhook منفصلة لكل وضع.",
    "Test": "اختبار",
    "Live": "مباشر",
    "Test secret key": "المفتاح السري للاختبار",
    "A restricted key (rk_test_…) with Checkout Sessions and Refunds access, from your XPay dashboard → Developers → API keys.":
        "مفتاح مقيّد (rk_test_…) بصلاحية جلسات الدفع والمبالغ المستردة، من لوحة تحكم XPay ← Developers ← API keys.",
    "Test publishable key": "المفتاح القابل للنشر للاختبار",
    "pk_test_… key, used by the secure payment window in the browser.":
        "مفتاح pk_test_…، تستخدمه نافذة الدفع الآمنة في المتصفح.",
    "Test webhook signing secret": "سر توقيع webhook للاختبار",
    "whsec_… secret for a webhook endpoint pointing at %s (events: checkout.session.completed, checkout.session.expired, payment_intent.payment_failed, charge.refunded, refund.failed).":
        "سر whsec_… لنقطة استقبال webhook تشير إلى %s (الأحداث: checkout.session.completed وcheckout.session.expired).",
    "Live secret key": "المفتاح السري المباشر",
    "A restricted key (rk_live_…) with Checkout Sessions and Refunds access.":
        "مفتاح مقيّد (rk_live_…) بصلاحية جلسات الدفع والمبالغ المستردة.",
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
    "Offer valU as its own option": "إظهار ڤاليو كخيار مستقل",
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
    "XPay session creation failed [%1$s]: %2$s": "فشل إنشاء جلسة XPay ‏[%1$s]: %2$s",
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
    "XPay refund of %1$s submitted (%2$s). Reason: %3$s": "تم إرسال استرداد XPay بمبلغ %1$s ‏(%2$s). السبب: %3$s",
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
    "XPay: the legacy XPay plugin is still active alongside this one. Shoppers currently see two separate XPay options at checkout. Deactivate the legacy plugin. Its settings are separate, and this plugin keeps its own keys and orders.":
        "XPay: إضافة XPay القديمة ما زالت مفعّلة إلى جانب هذه الإضافة. يرى المتسوقون حاليًا خيارَي XPay منفصلين عند إتمام الشراء. عطّل الإضافة القديمة — إعداداتها منفصلة، وهذه الإضافة تحتفظ بمفاتيحها وطلباتها الخاصة.",
    "Open the Plugins page": "فتح صفحة الإضافات",
    "Dismiss: I am mid-migration and know": "تجاهل — أنا في منتصف عملية الانتقال وأعلم بذلك",
    "Set up XPay": "إعداد XPay",
    "Not connected": "غير متصل",
    "Three steps · about five minutes": "ثلاث خطوات · نحو خمس دقائق",
    "Connected: Live mode": "متصل — الوضع المباشر",
    "Connected: Test mode": "متصل — وضع الاختبار",
    "Live mode: keys not validated yet": "الوضع المباشر — لم يتم التحقق من المفاتيح بعد",
    "Test mode: keys not validated yet": "وضع الاختبار — لم يتم التحقق من المفاتيح بعد",
    "Open XPay dashboard ↗": "فتح لوحة تحكم XPay ↗",
    "Connect your test keys": "أدخل مفاتيح الاختبار",
    "From your XPay dashboard → Developers → API keys. Test keys never move real money.":
        "من لوحة تحكم XPay ← Developers ← API keys. مفاتيح الاختبار لا تحرّك أموالًا حقيقية أبدًا.",
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

    # valU wallet number, asked for on the checkout page when nothing the
    # order holds can be sent as the wallet the payment will spend.
    "Mobile number for your valU wallet":
        "رقم الموبايل الخاص بمحفظة ڤاليو",
    "valU pays from the wallet registered to your mobile number, and the number on this order is not an Egyptian or Jordanian mobile. Enter the one your valU account uses.":
        "تدفع ڤاليو من المحفظة المسجّلة برقم موبايلك، والرقم الموجود على هذا الطلب ليس رقم موبايل مصري أو أردني. أدخل الرقم المسجّل بحساب ڤاليو.",
    "valU pays from the wallet registered to your mobile number. Enter the Egyptian or Jordanian mobile your valU account uses.":
        "تدفع ڤاليو من المحفظة المسجّلة برقم موبايلك. أدخل رقم الموبايل المصري أو الأردني المسجّل بحساب ڤاليو.",
    "Enter the mobile number registered to your valU wallet.":
        "أدخل رقم الموبايل المسجّل بمحفظة ڤاليو.",
    "That is not an Egyptian or Jordanian mobile number. Check the number registered to your valU wallet and try again.":
        "هذا ليس رقم موبايل مصري أو أردني. راجع الرقم المسجّل بمحفظة ڤاليو وحاول مرة أخرى.",
    # Store API schema descriptions for the wallet-number verdict. Not
    # shopper-facing, but the generator translates every msgid or fails.
    "Whether the shopper must be asked for a valU wallet number.":
        "ما إذا كان يجب سؤال المتسوق عن رقم محفظة ڤاليو.",
    "Whether the order already carries a phone number of any kind.":
        "ما إذا كان الطلب يحمل بالفعل رقم هاتف من أي نوع.",

    "Shopper gave %s as their valU wallet number. The billing phone on this order was left as they entered it.":
        "أعطى المتسوق %s كرقم محفظة ڤاليو. تُرك رقم هاتف الفوترة في هذا الطلب كما أدخله.",
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
    f.write("\n".join(out) + "\n")
print(f"wrote {OUT}: {sum(1 for _, i2, _ in entries if unquote(i2))} strings translated")
