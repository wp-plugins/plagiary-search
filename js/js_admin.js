/* =====================================================================================
*
*  Stop plagiary search
*
*/


function stopPlagiary() {
	var r= window.confirm("Confirm?");
	if (r==true) {
		jQuery("#wait_process").show();
		jQuery("#stopButton").attr('disabled', 'disabled');
		jQuery("#plagiaryButton").attr('disabled', 'disabled');
		
		var arguments = {
			action: 'stopPlagiary'
		} 
		  
		//POST the data and append the results to the results div
		jQuery.post(ajaxurl, arguments, function(response) {
			jQuery("#currentSearchZone").html(response);
		}).error(function(x,e) { 
			if (x.status==0){
				//Offline
			} else if (x.status==500){
				jQuery("#detail_currentSearch").html("Error 500: The ajax request is retried");
				stopPlagiary() ; 
			} else {
				alert("Error "+x.status) ; 
			}
		});    
	}
}

function forceSearchPlagiary() {
	jQuery("#wait_process").show();
	jQuery("#stopButton").attr('disabled', 'disabled');
	jQuery("#plagiaryButton").attr('disabled', 'disabled');
	
	var arguments = {
		action: 'forceSearchPlagiary'
	} 
	  
	//POST the data and append the results to the results div
	jQuery.post(ajaxurl, arguments, function(response) {
		jQuery("#detail_currentSearch").html(response);
		if (jQuery("#stopButton").is(':disabled')) {
			forceSearchPlagiary() ; 
		}
	}).error(function(x,e) { 
		if (x.status==0){
			//Offline
		} else if (x.status==500){
			jQuery("#detail_currentSearch").html("Error 500: The ajax request is retried");
			setTimeout("forceSearchPlagiary()", 2000);
		} else {
			alert("Error "+x.status) ; 
		}
	});  
}

function stopSearchPlagiary() {
	jQuery("#wait_process").hide();
	jQuery("#stopButton").removeAttr('disabled');
	jQuery("#plagiaryButton").removeAttr('disabled');
}

function notPlagiary(idEntry, msg) {
	var res = confirm(msg) ; 
	if (res==true) {
		var arguments = {
			action: 'notPlagiary' ,
			id: idEntry
		} 
		  
		//POST the data and append the results to the results div
		jQuery.post(ajaxurl, arguments, function(response) {
			if (response=="ok") {
				jQuery("#ligne"+idEntry).hide();
				window.location.href=window.location.href ; 
			} else {
				jQuery("#date"+idEntry).append(" "); // Just to stop the waiting image
				alert(response) ; 
			}
		}).error(function(x,e) { 
			alert("Error "+x.status) ; 
			jQuery("#date"+idEntry).append(" ");// Just to stop the waiting image
		});		
	} else {
		jQuery("#date"+idEntry).append(" ");// Just to stop the waiting image
	}
}

function plagiary(idEntry, msg) {
	var res = confirm(msg) ; 
	if (res==true) {
		var arguments = {
			action: 'plagiary' ,
			id: idEntry
		} 
		  
		//POST the data and append the results to the results div
		jQuery.post(ajaxurl, arguments, function(response) {
			if (response=="ok") {
				jQuery("#ligne"+idEntry).hide();
				window.location.href=window.location.href ; 
			} else {
				jQuery("#date"+idEntry).append(" "); // Just to stop the waiting image
				alert(response) ; 			
			}
		}).error(function(x,e) { 
			alert("Error "+x.status) ; 
			jQuery("#date"+idEntry).append(" ");// Just to stop the waiting image
		});		
	} else {
		jQuery("#date"+idEntry).append(" ");// Just to stop the waiting image
	}
}

function notAuthorized(idEntry, msg) {
	var res = confirm(msg) ; 
	if (res==true) {
		var arguments = {
			action: 'notAuthorized' ,
			id: idEntry
		} 
		  
		//POST the data and append the results to the results div
		jQuery.post(ajaxurl, arguments, function(response) {
			if (response=="ok") {
				jQuery("#ligne"+idEntry).hide();
				window.location.href=window.location.href ; 
			} else {
				jQuery("#date"+idEntry).append(" "); // Just to stop the waiting image
				alert(response) ; 
			}
		}).error(function(x,e) { 
			alert("Error "+x.status) ; 
			jQuery("#date"+idEntry).append(" ");// Just to stop the waiting image
		});		
	} else {
		jQuery("#date"+idEntry).append(" ");// Just to stop the waiting image
	}
}

function authorized(idEntry, msg) {
	var res = confirm(msg) ; 
	if (res==true) {
		var arguments = {
			action: 'authorized' ,
			id: idEntry
		} 
		  
		//POST the data and append the results to the results div
		jQuery.post(ajaxurl, arguments, function(response) {
			if (response=="ok") {
				jQuery("#ligne"+idEntry).hide();
				window.location.href=window.location.href ; 
			} else {
				jQuery("#date"+idEntry).append(" "); // Just to stop the waiting image
				alert(response) ; 			
			}
		}).error(function(x,e) { 
			alert("Error "+x.status) ; 
			jQuery("#date"+idEntry).append(" ");// Just to stop the waiting image
		});		
	} else {
		jQuery("#date"+idEntry).append(" ");// Just to stop the waiting image
	}
}

function delete_copy(idEntry, msg) {
	var res = confirm(msg) ; 
	if (res==true) {
		var arguments = {
			action: 'delete_copy' ,
			id: idEntry
		} 
		  
		//POST the data and append the results to the results div
		jQuery.post(ajaxurl, arguments, function(response) {
			if (response=="ok") {
				jQuery("#ligne"+idEntry).hide();
				window.location.href=window.location.href ; 
			} else {
				jQuery("#date"+idEntry).append(" "); // Just to stop the waiting image
				alert(response) ; 			
			}
		}).error(function(x,e) { 
			alert("Error "+x.status) ; 
			jQuery("#date"+idEntry).append(" ");// Just to stop the waiting image
		});		
	} else {
		jQuery("#date"+idEntry).append(" ");// Just to stop the waiting image
	}
}

function viewText(idEntry) {

	var arguments = {
		action: 'viewText',
		id: idEntry
	} 
	  
	//POST the data and append the results to the results div
	jQuery.post(ajaxurl, arguments, function(response) {
		jQuery("#plagiaryPopup").html(response);
	}).error(function(x,e) { 
		alert("Error "+x.status) ; 
		jQuery("#date"+idEntry).append(" ");// Just to stop the waiting image
	});		
}

function forceSearchPlagiary() {
	jQuery("#wait_process").show();
	jQuery("#stopButton").attr('disabled', 'disabled');
	jQuery("#plagiaryButton").attr('disabled', 'disabled');
	
	var arguments = {
		action: 'forceSearchPlagiary'
	} 
	  
	//POST the data and append the results to the results div
	jQuery.post(ajaxurl, arguments, function(response) {
		jQuery("#detail_currentSearch").html(response);
		if (jQuery("#stopButton").is(':disabled')) {
			forceSearchPlagiary() ; 
		}
	}).error(function(x,e) { 
		if (x.status==0){
			//Offline
		} else if (x.status==500){
			jQuery("#detail_currentSearch").html("Error 500: The ajax request is retried");
			setTimeout("forceSearchPlagiary()", 2000);
		} else {
			alert("Error "+x.status) ; 
		}
	});  
}

// Specific Search

function stopSpecificPlagiary() {
	var r= window.confirm("Confirm?");
	if (r==true) {
		jQuery("#wait_specificprocess").show();
		jQuery("#specificstopButton").attr('disabled', 'disabled');
		jQuery("#specificplagiaryButton").attr('disabled', 'disabled');
		
		var arguments = {
			action: 'stopSpecificPlagiary'
		} 
		  
		//POST the data and append the results to the results div
		jQuery.post(ajaxurl, arguments, function(response) {
			jQuery("#specificSearchZone").html(response);
			jQuery("#specificSearch_text").val('') ; 
		}).error(function(x,e) { 
			if (x.status==0){
				//Offline
			} else if (x.status==500){
				jQuery("#specificSearchZone").html("Error 500: The ajax request is retried");
				stopSpecificPlagiary() ; 
			} else {
				alert("Error "+x.status) ; 
			}
		});    
	}
}

function forceSearchSpecificPlagiary() {
	jQuery("#wait_specificprocess").show();
	jQuery("#specificstopButton").attr('disabled', 'disabled');
	jQuery("#specificplagiaryButton").attr('disabled', 'disabled');
	
	var arguments = {
		action: 'forceSearchSpecificPlagiary', 
		text: jQuery("#specificSearch_text").val()
	} 
	  
	//POST the data and append the results to the results div
	jQuery.post(ajaxurl, arguments, function(response) {
		jQuery("#detail_specificSearch").html(response);
		if ((response.indexOf("-End-") == -1) && (jQuery("#specificstopButton").is(':disabled'))) {
			forceSearchSpecificPlagiary() ; 
		} else {
			jQuery("#wait_specificprocess").hide();
		 	jQuery("#specificstopButton").removeAttr('disabled');
			jQuery("#specificplagiaryButton").removeAttr('disabled');
		}
	}).error(function(x,e) { 
		if (x.status==0){
			//Offline
		} else if (x.status==500){
			jQuery("#detail_specificSearch").html("Error 500: The ajax request is retried");
			setTimeout("forceSearchSpecificPlagiary()", 2000);
		} else {
			alert("Error "+x.status) ; 
		}
	});  
}

function stopSearchSpecificPlagiary() {
	jQuery("#wait_specificprocess").hide();
	jQuery("#specificstopButton").removeAttr('disabled');
	jQuery("#specificplagiaryButton").removeAttr('disabled');
}
