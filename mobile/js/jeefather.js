/* Plugin Jeefather - mobile : affichage simple, le détail passe par le dashboard */
$(".eqLogicDisplayCard").on("click", function () {
	$("#div_pageContainer").show();
	jeedom.eqLogic.print({
		type: "jeefather",
		id: $(this).attr("data-eqLogic_id"),
		success: function (data) {
			$("#div_pageContainer").html(data.html || "");
		}
	});
});
