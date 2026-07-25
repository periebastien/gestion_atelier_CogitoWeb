(function () {
	'use strict';

	function ready(callback) {
		if (document.readyState !== 'loading') {
			callback();
			return;
		}

		document.addEventListener('DOMContentLoaded', callback);
	}

	ready(function () {
		var calendarEl = document.getElementById('gacct-calendar');
		var tooltipEl = null;

		if (!calendarEl || typeof FullCalendar === 'undefined' || typeof GACCTCalendar === 'undefined') {
			return;
		}

		function removeTooltip() {
			if (tooltipEl && tooltipEl.parentNode) {
				tooltipEl.parentNode.removeChild(tooltipEl);
			}

			tooltipEl = null;
		}

		function moveTooltip(mouseEvent) {
			if (!tooltipEl) {
				return;
			}

			tooltipEl.style.left = mouseEvent.pageX + 12 + 'px';
			tooltipEl.style.top = mouseEvent.pageY + 12 + 'px';
		}

		function showTooltip(mouseEvent, services) {
			removeTooltip();

			if (!Array.isArray(services) || services.length === 0) {
				return;
			}

			tooltipEl = document.createElement('div');
			tooltipEl.className = 'gacct-services-tooltip';

			services.forEach(function (service) {
				var line = document.createElement('div');
				line.textContent = service;
				tooltipEl.appendChild(line);
			});

			document.body.appendChild(tooltipEl);
			moveTooltip(mouseEvent);
		}

		var calendar = new FullCalendar.Calendar(calendarEl, {
			initialView: 'dayGridMonth',
			locale: GACCTCalendar.locale || 'fr',
			height: 'auto',
			firstDay: 1,
			nowIndicator: true,
			navLinks: false,
			dayMaxEvents: 4,
			displayEventTime: false,
			slotEventOverlap: false,
			slotMinTime: (GACCTCalendar.openingTime || '09:00') + ':00',
			scrollTime: (GACCTCalendar.openingTime || '09:00') + ':00',
			eventOrder: 'extendedProps.sortIndex,start,title',
			headerToolbar: {
				left: 'prev,next today',
				center: 'title',
				right: 'dayGridMonth,timeGridWeek'
			},
			buttonText: {
				today: "Aujourd'hui",
				month: 'Mois',
				week: 'Semaine'
			},
			events: {
				url: GACCTCalendar.ajaxUrl,
				method: 'GET',
				extraParams: function () {
					return {
						action: GACCTCalendar.action,
						nonce: GACCTCalendar.nonce
					};
				},
				failure: function () {
					calendarEl.classList.add('gacct-calendar-error');
				}
			},
			eventDidMount: function (info) {
				var props = info.event.extendedProps || {};

				if (props.type !== 'occupation' || !Array.isArray(props.services) || props.services.length === 0) {
					return;
				}

				info.el.addEventListener('mouseenter', function (event) {
					showTooltip(event, props.services);
				});

				info.el.addEventListener('mousemove', moveTooltip);
				info.el.addEventListener('mouseleave', removeTooltip);
			},
			eventClick: function (info) {
				var type = info.event.extendedProps ? info.event.extendedProps.type : '';

				if (type !== 'occupation') {
					info.jsEvent.preventDefault();
					return;
				}

				if (info.event.url) {
					info.jsEvent.preventDefault();
					window.open(info.event.url, '_blank', 'noopener');
				}
			}
		});

		calendar.render();
	});
})();
