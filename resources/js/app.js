import Alpine from 'alpinejs';
import Sortable from 'sortablejs';
import './crm-datepicker';
import './crm-monthpicker';
import './crm-timepicker';
import './crm-select';
import './crm-bulk';
import { lockBodyScroll, unlockBodyScroll } from './body-scroll-lock';
import registerPresence from './presence';
import registerConflict from './conflict';
import registerNotifications from './notifications';
import registerSync from './crm-sync';
import registerToasts from './toast';
import registerSalesDailyReminder from './sales-daily-reminder';
import registerComments from './comments';
import registerCrmShell from './crm-shell';
import registerCrmModal from './crm-modal';
import registerSalesPocketbook from './sales-pocketbook';
import registerPwa from './pwa';
import registerDanaTalangan from './dana-talangan';

window.Alpine = Alpine;
window.Sortable = Sortable;
window.oasisBodyScroll = { lock: lockBodyScroll, unlock: unlockBodyScroll };

registerPresence(Alpine);
registerConflict(Alpine);
registerNotifications(Alpine);
registerSync(Alpine);
registerToasts(Alpine);
registerSalesDailyReminder(Alpine);
registerComments(Alpine);
registerCrmShell(Alpine);
registerCrmModal(Alpine);
registerSalesPocketbook(Alpine);
registerPwa(Alpine);
registerDanaTalangan(Alpine);

Alpine.start();
