const WEEKDAY_TARGET_MINUTES = 480;

export function isWeekendDate(date) {
    const day = new Date(`${date}T12:00:00`).getDay();

    return day === 0 || day === 6;
}

export function scheduleForDate(date) {
    if (isWeekendDate(date)) {
        return {
            isWeekend: true,
            startTime: null,
            endTime: null,
            breakStart: null,
            breakEnd: null,
            targetMinutes: 0,
        };
    }

    const isFriday = new Date(`${date}T12:00:00`).getDay() === 5;

    return {
        isWeekend: false,
        startTime: isFriday ? '07:30' : '08:00',
        endTime: '17:00',
        breakStart: isFriday ? '11:30' : '12:00',
        breakEnd: '13:00',
        targetMinutes: WEEKDAY_TARGET_MINUTES,
    };
}

export function formatAgendaTime(minutes) {
    const safeMinutes = Math.max(0, Math.min(Number(minutes) || 0, 24 * 60 - 1));

    return `${String(Math.floor(safeMinutes / 60)).padStart(2, '0')}:${String(safeMinutes % 60).padStart(2, '0')}`;
}

export function resolveAgendaDuration(presetValue, customValue) {
    return String(presetValue || customValue || '');
}

export function calculateAgendaSlot({
    date,
    agendas = [],
    requestedStart = null,
    durationMinutes,
    schedule = scheduleForDate(date),
    allowOutsideSchedule = false,
}) {
    const duration = Number(durationMinutes);
    if (!Number.isInteger(duration) || duration < 5 || duration % 5 !== 0) {
        return invalidResult('Durasi harus berupa kelipatan 5 menit.');
    }

    const existingAgendas = agendas
        .filter((agenda) => !['cancelled', 'rescheduled'].includes(agenda.status))
        .filter((agenda) => !Boolean(agenda.isOutsideSchedule ?? agenda.is_outside_schedule))
        .map((agenda) => ({
            start: timeToMinutes(agenda.startTime ?? agenda.start_time),
            end: timeToMinutes(agenda.endTime ?? agenda.end_time),
        }))
        .filter((agenda) => agenda.start !== null && agenda.end !== null);

    const normalMinutes = existingAgendas
        .reduce((total, agenda) => total + effectiveMinutes(agenda.start, agenda.end, schedule), 0);

    let start = timeToMinutes(requestedStart);
    const automaticStart = start === null;
    if (start === null) {
        if (schedule.isWeekend) {
            return invalidResult('Pilih jam mulai untuk agenda akhir pekan.');
        }

        start = existingAgendas.reduce(
            (latest, agenda) => Math.max(latest, agenda.end),
            timeToMinutes(schedule.startTime),
        );
    }

    if (!schedule.isWeekend && !allowOutsideSchedule) {
        const workStart = timeToMinutes(schedule.startTime);
        const workEnd = timeToMinutes(schedule.endTime);
        const breakStart = timeToMinutes(schedule.breakStart);
        const breakEnd = timeToMinutes(schedule.breakEnd);

        start = Math.max(start, workStart);
        if (automaticStart && start >= breakStart && start < breakEnd) {
            start = breakEnd;
        }
        if (automaticStart && start < breakStart && start + duration > breakStart) {
            start = breakEnd;
        }

        if (start + duration > workEnd) {
            return invalidResult('Agenda harus selesai sebelum jam 17:00.');
        }

    } else if (start + duration >= 24 * 60) {
        return invalidResult('Agenda harus selesai pada hari yang sama.');
    }

    const end = start + duration;
    const effectiveDuration = effectiveMinutes(start, end, schedule);
    const overlaps = !allowOutsideSchedule && existingAgendas.some((agenda) => start < agenda.end && end > agenda.start);
    if (overlaps) {
        return invalidResult('Waktu agenda bertumpang tindih dengan agenda lain.');
    }

    return {
        ok: true,
        startTime: formatAgendaTime(start),
        endTime: formatAgendaTime(end),
        durationMinutes: duration,
        remainingMinutes: schedule.isWeekend
            ? null
            : Math.max(0, (schedule.targetMinutes ?? WEEKDAY_TARGET_MINUTES) - normalMinutes - (allowOutsideSchedule ? 0 : effectiveDuration)),
        message: '',
    };
}

function timeToMinutes(value) {
    if (!value || !/^\d{2}:\d{2}$/.test(String(value).slice(0, 5))) {
        return null;
    }

    const [hours, minutes] = String(value).slice(0, 5).split(':').map(Number);
    if (hours > 23 || minutes > 59) {
        return null;
    }

    return (hours * 60) + minutes;
}

function effectiveMinutes(start, end, schedule) {
    const duration = Math.max(0, end - start);
    if (duration === 0 || schedule.isWeekend) {
        return duration;
    }

    const breakStart = timeToMinutes(schedule.breakStart);
    const breakEnd = timeToMinutes(schedule.breakEnd);
    const breakMinutes = Math.max(0, Math.min(end, breakEnd) - Math.max(start, breakStart));

    return Math.max(0, duration - breakMinutes);
}

function invalidResult(message) {
    return {
        ok: false,
        startTime: null,
        endTime: null,
        durationMinutes: null,
        remainingMinutes: null,
        message,
    };
}
