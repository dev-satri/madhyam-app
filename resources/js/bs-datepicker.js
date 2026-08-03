/**
 * BS (Bikram Sambat) Date Picker — Alpine.js data component
 * Calendar data sourced from anuzpandey/laravel-nepali-date
 * Epoch: BS 2000-01-01 = AD 1943-04-14
 */
(function () {
    const BS_CALENDAR_DATA = {
        2000: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2001: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2002: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2003: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2004: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2005: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2006: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2007: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2008: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
        2009: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2010: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2011: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2012: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2013: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2014: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2015: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2016: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2017: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2018: [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2019: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2020: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2021: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2022: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2023: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2024: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2025: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2026: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2027: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2028: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2029: [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30],
        2030: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2031: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2032: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2033: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2034: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2035: [30, 32, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
        2036: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2037: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2038: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2039: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2040: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2041: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2042: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2043: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2044: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2045: [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2046: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2047: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2048: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2049: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2050: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2051: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2052: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2053: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2054: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2055: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2056: [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30],
        2057: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2058: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2059: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2060: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2061: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2062: [30, 32, 31, 32, 31, 31, 29, 30, 29, 30, 29, 31],
        2063: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2064: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2065: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2066: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
        2067: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2068: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2069: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2070: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
        2071: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2072: [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2073: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
        2074: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2075: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2076: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2077: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2078: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
        2079: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2080: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
        2081: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
        2082: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2083: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
        2084: [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
        2085: [31, 32, 31, 32, 30, 31, 30, 30, 29, 30, 30, 30],
        2086: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2087: [31, 31, 32, 31, 31, 31, 30, 30, 29, 30, 30, 30],
        2088: [30, 31, 32, 32, 30, 31, 30, 30, 29, 30, 30, 30],
        2089: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2090: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2091: [31, 31, 32, 31, 31, 31, 30, 30, 29, 30, 30, 30],
        2092: [30, 31, 32, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2093: [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
        2094: [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
        2095: [31, 31, 32, 31, 31, 31, 30, 29, 30, 30, 30, 30],
        2096: [30, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
        2097: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
        2098: [31, 31, 32, 31, 31, 31, 29, 30, 29, 30, 29, 31],
        2099: [31, 31, 32, 31, 31, 31, 30, 29, 29, 30, 30, 30]
    };

    const BS_MONTHS = [
        'Baisakh',
        'Jestha',
        'Asar',
        'Shrawan',
        'Bhadra',
        'Aswin',
        'Kartik',
        'Mangsir',
        'Poush',
        'Magh',
        'Falgun',
        'Chaitra'
    ];

    const BS_EPOCH_AD = new Date(Date.UTC(1943, 3, 14));

    function padZero(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    function bsToAd(bsY, bsM, bsD) {
        let totalDays = 0;
        for (let y = 2000; y < bsY; y++) {
            if (BS_CALENDAR_DATA[y]) {
                for (let m = 0; m < 12; m++) totalDays += BS_CALENDAR_DATA[y][m];
            }
        }
        if (BS_CALENDAR_DATA[bsY]) {
            for (let m = 1; m < bsM; m++) totalDays += BS_CALENDAR_DATA[bsY][m - 1];
        }
        totalDays += bsD - 1;

        const result = new Date(BS_EPOCH_AD.getTime() + totalDays * 86400000);
        return { y: result.getUTCFullYear(), m: result.getUTCMonth() + 1, d: result.getUTCDate() };
    }

    function adToBs(adY, adM, adD) {
        const adDate = new Date(Date.UTC(adY, adM - 1, adD));
        let diffDays = Math.floor((adDate - BS_EPOCH_AD) / 86400000);

        if (diffDays < 0) return { y: 2000, m: 1, d: 1 };

        let bsYear = 2000;
        while (bsYear <= 2099) {
            const yearData = BS_CALENDAR_DATA[bsYear];
            if (!yearData) {
                bsYear++;
                continue;
            }
            const daysInYear = yearData.reduce((a, b) => a + b, 0);
            if (diffDays < daysInYear) break;
            diffDays -= daysInYear;
            bsYear++;
        }
        if (bsYear > 2099) return { y: 2099, m: 12, d: 31 };

        const yearData = BS_CALENDAR_DATA[bsYear] || [30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30];
        let bsMonth = 1;
        while (bsMonth <= 12) {
            const daysInMonth = yearData[bsMonth - 1];
            if (diffDays < daysInMonth) break;
            diffDays -= daysInMonth;
            bsMonth++;
        }

        return { y: bsYear, m: bsMonth, d: diffDays + 1 };
    }

    function getDaysInBsMonth(year, month) {
        return BS_CALENDAR_DATA[year] ? BS_CALENDAR_DATA[year][month - 1] : 30;
    }

    function getFirstDayOfWeekBs(year, month) {
        const ad = bsToAd(year, month, 1);
        const d = new Date(Date.UTC(ad.y, ad.m - 1, ad.d));
        return d.getUTCDay();
    }

    function bsDatePicker(config) {
        return {
            showPicker: false,
            adValue: config.modelValue || '',
            bsValue: '',
            bsDisplayValue: '',
            currentBsYear: 2083,
            currentBsMonth: 1,
            bsDays: [],
            bsMonthNames: BS_MONTHS,
            wireModel: config.wireModel,

            init() {
                if (this.adValue && /^\d{4}-\d{2}-\d{2}$/.test(this.adValue)) {
                    const parts = this.adValue.split('-');
                    const bs = adToBs(parseInt(parts[0]), parseInt(parts[1]), parseInt(parts[2]));
                    this.bsValue = bs.y + '-' + padZero(bs.m) + '-' + padZero(bs.d);
                    this.bsDisplayValue = this.bsValue;
                    this.currentBsYear = bs.y;
                    this.currentBsMonth = bs.m;
                } else {
                    const now = new Date();
                    const bs = adToBs(now.getUTCFullYear(), now.getUTCMonth() + 1, now.getUTCDate());
                    this.currentBsYear = bs.y;
                    this.currentBsMonth = bs.m;
                    this.bsDisplayValue = '';
                }
                this.loadMonth();
            },

            togglePicker() {
                this.showPicker = !this.showPicker;
                if (this.showPicker) this.loadMonth();
            },

            loadMonth() {
                const totalDays = getDaysInBsMonth(this.currentBsYear, this.currentBsMonth);
                const startDow = getFirstDayOfWeekBs(this.currentBsYear, this.currentBsMonth);
                const days = [];

                for (let i = 0; i < startDow; i++) {
                    days.push({ d: 0, bs: '', pad: true });
                }
                for (let d = 1; d <= totalDays; d++) {
                    const bsStr = this.currentBsYear + '-' + padZero(this.currentBsMonth) + '-' + padZero(d);
                    days.push({ d: d, bs: bsStr, pad: false });
                }
                const remaining = 7 - (days.length % 7);
                if (remaining < 7) {
                    for (let i = 0; i < remaining; i++) {
                        days.push({ d: 0, bs: '', pad: true });
                    }
                }
                this.bsDays = days;
            },

            prevMonth() {
                if (this.currentBsMonth === 1) {
                    this.currentBsMonth = 12;
                    this.currentBsYear--;
                } else {
                    this.currentBsMonth--;
                }
                this.loadMonth();
            },

            nextMonth() {
                if (this.currentBsMonth === 12) {
                    this.currentBsMonth = 1;
                    this.currentBsYear++;
                } else {
                    this.currentBsMonth++;
                }
                this.loadMonth();
            },

            selectDay(day) {
                if (!day.d || day.pad) return;
                this.bsValue = day.bs;
                this.bsDisplayValue = day.bs;

                const parts = day.bs.split('-');
                const ad = bsToAd(parseInt(parts[0]), parseInt(parts[1]), parseInt(parts[2]));
                this.adValue = ad.y + '-' + padZero(ad.m) + '-' + padZero(ad.d);

                if (this.wireModel && typeof Livewire !== 'undefined') {
                    try {
                        this.$wire.set(this.wireModel, this.adValue);
                    } catch { /* Livewire not available */ }
                }

                this.showPicker = false;
            }
        };
    }

    window.bsDatePicker = bsDatePicker;
})();
