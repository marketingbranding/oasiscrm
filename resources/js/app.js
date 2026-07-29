import Alpine from 'alpinejs';
import Sortable from 'sortablejs';
import './crm-datepicker';
import './crm-monthpicker';
import './crm-timepicker';
import './crm-select';
import './crm-bulk';
import registerPresence from './presence';
import registerConflict from './conflict';
import registerNotifications from './notifications';
import registerSync from './crm-sync';
import registerToasts from './toast';
import registerSalesDailyReminder from './sales-daily-reminder';
import registerComments from './comments';

window.Alpine = Alpine;
window.Sortable = Sortable;

registerPresence(Alpine);
registerConflict(Alpine);
registerNotifications(Alpine);
registerSync(Alpine);
registerToasts(Alpine);
registerSalesDailyReminder(Alpine);
registerComments(Alpine);

Alpine.start();
