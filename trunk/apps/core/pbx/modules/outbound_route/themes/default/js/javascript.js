$(document).ready(function(){

        $("select.goto").trigger('change');
	//$('#sortable2').trigger('sortupdate') ;
	$("#arrDestine").val(getArrRows()); 
	$("#arrTrunks").val(getIdTrunks()); 
	
	
});

$(function() {
 
  $( "#sortable1, #sortable2" ).sortable({
  connectWith: ".connectedSortable"
 
  }).disableSelection();
  
  $("#sortable2").bind( "sortupdate", function(event, ui) {
            var newOrder = $(this).sortable('toArray').toString();
            var _parent = Number(ui.item[0].parentElement.id);
            var id_item = ui.item[0].id;
            if (!isNaN(_parent))
                id_parent = _parent;
	    $("#arrTrunks").val(newOrder);
            
        
  })
  
});




$(window).load(function () {
        $("div.neo-module-content").attr("style","");
});


if($("#mode_input").val()=="input")
   var index=0;


function getIdTrunks(){
  var rows =0;
  var lastRow = getNumRows();
  var valIndex = "";
	$('.tab .content .tabForm ul#sortable2 li.ui-state-default input').each(function() {
	    rows = $(this).attr("id");
	    valIndex += rows+",";
	  
  }); 
  return valIndex;
   
}

function getArrRows(){
  var rows =0;
  var lastRow = getNumRows();
  var valIndex = "";
	$('table#destine tr.content-destine').each(function() {
	    rows++;
	if(rows==lastRow)
	  valIndex += rows;
	else
	  valIndex += rows+",";
	  
  }); 
  return valIndex;
   
}

function getNumRows(){
        var rows =0;
 	$('table#destine tr.content-destine').each(function() {
	    rows++;
	}); 
	return rows;
}

var add = function() {
     index ++;
     if(isNaN(index))
	index=1;
     
     if (($("#mode_input").val()=="edit")&& ($("#mostra_adv").val()==""))
         index = $("#index").val();
    
     var row = $('table#destine tr#test').html();
     if(typeof  row!== "undefined" && row)
     {
	var arrDestine = $("#arrDestine").val();
	if(index==1)
	  arrDestine = index;
	else{
	  arrDestine = arrDestine+","+index;
	  arrDestine = arrDestine.replace(",,",",");
	}
	$("#arrDestine").val(arrDestine);
	$("#mostra_adv").val("val");
	
	row = row.replace(/\__/g, index);
	var val = "<tr id="+index+">"+row+"</tr>"; 
	$('table#destine tbody').append(val);
	$("#goto"+index).addClass("goto");
	$("#"+index).addClass("content-destine");
	
     }
     
};

$('.add').live('click', this, function(event) {
            add();
            
    });

$('.delete').live('click', this, function(event) {
     //var index = $('table#destine tbody tr').length;    
     //if (index!=2){
       
	var arrDestine = $("#arrDestine").val();
	var id =  $(this).closest('tr').attr("id");
	arrDestine = arrDestine.replace(id,"");
	arrDestine = arrDestine.replace(",,",",");
	$(this).closest('tr').remove();
	$("#arrDestine").val(arrDestine);
    // }
     
});





function radio(id_radio){
    var alt=$("#content_"+id_radio).children("table").height();
    var alt_tab=alt+10;
    $(".tabs").css({'height':alt_tab});
    $(".content").css({'height':'0'});
    $("#content_"+id_radio).css({'height':''});
    $(".content").css({"z-index":"0"});
    $("div.tab > .content > *").css({"opacity":"0", "-moz-transform": "translateX(-100%)","-webkit-transform":"translateX(-100%)","-o-transform":"translateX(-100%)","-moz-transition":"all 0.6s ease","-webkit-transition":"all 0.6s ease","-o-transition":"all 0.6s ease"});
    $("#content_"+id_radio).css({"z-index":"1"});
    $("#content_"+id_radio+" > *").css({"opacity":"1", "-moz-transform":"translateX(0)", "-webkit-transform":"translateX(0)", "-o-transform":"translateX(0)", "-ms-transform":"translateX(0)"});
    
    //div de las tabs
    var d_label=$("#"+id_radio).parent();
    $(".neo-table-header-row-filter").css("background","none");
    $(".neo-table-header-row-filter").css("color","BLACK");
    d_label.css("background","-moz-linear-gradient(center top , #777777, #999999)");
    d_label.css("background","-webkit-gradient(linear,0% 40%,0% 70%,from(#777),to(#999))");
    d_label.css("background","linear-gradient(center top , #777777, #999999)");
    d_label.css("border-color"," #888888"); 
    d_label.css("color"," #FFFFFF"); 
    //$(".content").css({'display':'none'});
    //$("#content_"+id_radio).css("display","");
}



