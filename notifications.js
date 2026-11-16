(function () {
    const reminderStorageKey = "studysync-fired-reminders";
    const taskStorageKey = "studysync-tasks";
    const notificationAudio = new Audio("notification.mp3.wav");
    notificationAudio.preload = "auto";
    let pendingSoundMode = null;
    let soundErrorReported = false;
    const firedReminders = new Set();

    try {
        const savedReminders = JSON.parse(localStorage.getItem(reminderStorageKey) || "[]");
        if (Array.isArray(savedReminders)) {
            savedReminders.forEach((reminder) => {
                if (typeof reminder === "string") firedReminders.add(reminder);
            });
        }
    } catch (error) {
        console.error("Notification reminders could not be loaded.", error);
    }

    function reportSoundError(error) {
        if (soundErrorReported) return;
        console.error("Notification sound could not be played. Check that notification.mp3.wav exists beside the HTML files.", error);
        soundErrorReported = true;
    }

    function playAudio(mode) {
        pendingSoundMode = mode;
        notificationAudio.loop = mode === "repeat";
        notificationAudio.currentTime = 0;
        notificationAudio.play().then(() => {
            if (pendingSoundMode === mode) pendingSoundMode = null;
        }).catch((error) => {
            if (error.name !== "NotAllowedError") {
                pendingSoundMode = null;
                reportSoundError(error);
            }
        });
    }

    function playNotificationSound() {
        playAudio("once");
    }

    function playRepeatedNotificationSound() {
        playAudio("repeat");
    }

    function stopRepeatedNotificationSound() {
        if (notificationAudio.loop || pendingSoundMode === "repeat") {
            notificationAudio.pause();
            notificationAudio.currentTime = 0;
            notificationAudio.loop = false;
        }
        if (pendingSoundMode === "repeat") pendingSoundMode = null;
    }

    function unlockAudio() {
        if (pendingSoundMode) playAudio(pendingSoundMode);
    }

    function checkDueTasks() {
        let tasks;
        try {
            tasks = JSON.parse(localStorage.getItem(taskStorageKey) || "[]");
        } catch (error) {
            console.error("Due-task reminders could not be checked.", error);
            return;
        }
        if (!Array.isArray(tasks)) return;

        const now = new Date();
        let hasNewReminder = false;
        tasks.forEach((task) => {
            if (!task || task.completed || typeof task.id !== "string") return;
            if (typeof task.dueDate !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(task.dueDate)) return;
            if (typeof task.dueTime !== "string" || !/^\d{2}:\d{2}$/.test(task.dueTime)) return;

            const dueAt = new Date(`${task.dueDate}T${task.dueTime}:00`);
            if (Number.isNaN(dueAt.getTime()) || dueAt > now) return;

            const reminderKey = `${task.id}|${task.dueDate}|${task.dueTime}`;
            if (firedReminders.has(reminderKey)) return;
            firedReminders.add(reminderKey);
            hasNewReminder = true;
        });

        if (!hasNewReminder) return;
        try {
            localStorage.setItem(reminderStorageKey, JSON.stringify([...firedReminders]));
        } catch (error) {
            console.error("Due-task reminder status could not be saved.", error);
        }
        playNotificationSound();
    }

    document.addEventListener("pointerdown", unlockAudio, true);
    document.addEventListener("keydown", unlockAudio, true);
    window.addEventListener("pageshow", checkDueTasks);
    window.addEventListener("storage", (event) => {
        if (event.key === taskStorageKey || event.key === null) checkDueTasks();
    });
    window.setInterval(checkDueTasks, 30000);
    checkDueTasks();

    window.StudySyncNotifications = {
        play: playNotificationSound,
        playRepeated: playRepeatedNotificationSound,
        stopRepeated: stopRepeatedNotificationSound
    };
})();
