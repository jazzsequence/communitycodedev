/* global Chart, ccSearchAnalytics */
( function () {
	'use strict';

	if ( typeof Chart === 'undefined' || typeof ccSearchAnalytics === 'undefined' ) {
		return;
	}

	var blue = { border: 'rgb(28,151,234)', bg: 'rgba(28,151,234,0.1)' };

	var baseOpts = {
		responsive: true,
		maintainAspectRatio: false,
		plugins: {
			legend: { display: false },
			tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', padding: 10 },
		},
		scales: {
			x: { grid: { display: false }, ticks: { font: { size: 11 } } },
			y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 } } },
		},
	};

	var dailyEl = document.getElementById( 'cc-sa-daily-chart' );
	if ( dailyEl && ccSearchAnalytics.daily.labels.length ) {
		new Chart( dailyEl.getContext( '2d' ), {
			type: 'line',
			data: {
				labels: ccSearchAnalytics.daily.labels,
				datasets: [ {
					label: 'Searches',
					data: ccSearchAnalytics.daily.counts,
					borderColor: blue.border,
					backgroundColor: blue.bg,
					borderWidth: 3,
					pointRadius: 4,
					pointHoverRadius: 6,
					pointBackgroundColor: blue.border,
					pointBorderColor: '#fff',
					pointBorderWidth: 2,
					fill: true,
					tension: 0.4,
				} ],
			},
			options: baseOpts,
		} );
	}

	var termsEl = document.getElementById( 'cc-sa-terms-chart' );
	if ( termsEl && ccSearchAnalytics.terms.labels.length ) {
		new Chart( termsEl.getContext( '2d' ), {
			type: 'bar',
			data: {
				labels: ccSearchAnalytics.terms.labels,
				datasets: [ {
					label: 'Searches',
					data: ccSearchAnalytics.terms.counts,
					backgroundColor: blue.bg,
					borderColor: blue.border,
					borderWidth: 2,
				} ],
			},
			options: {
				indexAxis: 'y',
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: { display: false },
					tooltip: { backgroundColor: 'rgba(0,0,0,0.8)', padding: 10 },
				},
				scales: {
					x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { font: { size: 11 } } },
					y: { grid: { display: false }, ticks: { font: { size: 11 } } },
				},
			},
		} );
	}
} )();
