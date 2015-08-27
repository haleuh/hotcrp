// settings.js -- HotCRP JavaScript library for settings
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function next_lexicographic_permutation(i, size) {
    var y = (i & -i) || 1, c = i + y, highbit = 1 << size;
    i = (((i ^ c) >> 2) / y) | c;
    if (i >= highbit) {
        i = ((i & (highbit - 1)) << 2) | 3;
        if (i >= highbit)
            i = false;
    }
    return i;
}


function do_option_type(e, nohilite) {
    var m;
    if (!nohilite)
        hiliter(e);
    if ((m = e.name.match(/^optvt(.*)$/))) {
        fold("optv" + m[1], e.value != "selector" && e.value != "radio");
        fold("optvis" + m[1], !/:final/.test(e.value), 2);
        fold("optvis" + m[1], e.value != "pdf:final", 3);
    }
}


function settings_add_track() {
    var i, h, j;
    for (i = 1; jQuery("#trackgroup" + i).length; ++i)
        /* do nothing */;
    jQuery("#trackgroup" + (i - 1)).after("<div id=\"trackgroup" + i + "\"></div>");
    j = jQuery("#trackgroup" + i);
    j.html(jQuery("#trackgroup0").html().replace(/_track0/g, "_track" + i));
    hiliter_children(j);
    j.find("input[hottemptext]").each(mktemptext);
}


window.review_round_settings = (function () {
var added = 0;

function namechange() {
    var roundnum = this.id.substr(10), name = jQuery.trim(jQuery(this).val());
    jQuery("#rev_roundtag_" + roundnum).text(name === "" ? "(no name)" : name);
}

function init() {
    jQuery("#roundtable input[type=text]").on("input change", namechange);
}

function add() {
    var i, h, j;
    for (i = 1; jQuery("#roundname_" + i).length; ++i)
        /* do nothing */;
    jQuery("#round_container").show();
    jQuery("#roundtable").append(jQuery("#newround").html().replace(/\$/g, i));
    if (++added == 1 && i == 1)
        jQuery("div[hotroundnum=" + i + "] > :first-child").append('<div class="hint">Example name: “R1”</div>');
    jQuery("#rev_roundtag").append('<option value="#' + i + '" id="rev_roundtag_' + i + '">(new round)</option>');
    jQuery("div[hotroundnum=" + i + "] input.temptext").each(mktemptext);
    jQuery("#roundname_" + i).focus().on("input change", namechange);
}

function kill(e) {
    var divj = jQuery(e).closest("div[hotroundnum]"),
        roundnum = divj.attr("hotroundnum"),
        vj = divj.find("input[name=deleteround_" + roundnum + "]"),
        ej = divj.find("input[name=roundname_" + roundnum + "]");
    if (vj.val()) {
        vj.val("");
        ej.val(ej.attr("hotroundname"));
        ej.removeClass("dim").prop("disabled", false);
        jQuery(e).html("Delete round");
    } else {
        vj.val(1);
        var x = ej.val();
        ej.attr("hotroundname", x);
        ej.val(x == "(no name)" ? "(deleted)" : "(" + ej.val() + " deleted)")
            .addClass("dim").prop("disabled", true);
        jQuery(e).html("Restore round");
    }
    divj.find("table").toggle(!vj.val());
    hiliter(e);
}

return {init: init, add: add, kill: kill};
})();


window.review_form_settings = (function () {
var fieldmap, fieldorder, original, samples,
    colors = ["sv", "Red to green", "svr", "Green to red",
              "sv-blpu", "Blue to purple", "sv-publ", "Purple to blue"];

function get_fid(elt) {
    return elt.id.replace(/^.*_/, "");
}

function options_to_text(fieldj) {
    var cc = 49, ccdelta = 1, i, t = [];
    if (!fieldj.options)
        return "";
    if (fieldj.option_letter) {
        cc = fieldj.option_letter.charCodeAt(0) + fieldj.options.length - 1;
        ccdelta = -1;
    }
    for (i = 0; i != fieldj.options.length; ++i, cc += ccdelta)
        t.push(String.fromCharCode(cc) + ". " + fieldj.options[i]);
    fieldj.option_letter && t.reverse();
    t.length && t.push(""); // get a trailing newline
    return t.join("\n");
}

/* parse HTML form into JSON review form description -- currently unused
function parse_field(fid) {
    var fieldj = {name: $("#shortName_" + fid).val()}, x;
    if ((x = $("#order_" + fid).val()))
        fieldj.position = x|0;
    if ((x = $.trim($("#description_" + fid).val())) !== "")
        fieldj.description = x;
    if ((x = $("#options_" + fid).val()) != "pc")
        fieldj.view_score = x;
    if (original[fid].options) {
        if (!text_to_options(fieldj, $("#options_" + fid).val()))
            return false;
        x = $("#option_class_prefix_" + fid).val() || "sv";
        if ($("#option_class_prefix_flipped_" + fid).val())
            x = colors[(colors.indexOf(x) || 0) ^ 2];
        if (x != "sv")
            fieldj.option_class_prefix = x;
    }
    return fieldj;
}

function text_to_options(fieldj, text) {
    var lines = $.split(/[\r\n\v]+/), i, s, cc, xlines = [], m;
    for (i in lines)
        if ((s = $.trim(lines[i])) !== "")
            xlines.push(s);
    xlines.sort();
    if (xlines.length >= 1 && xlines.length <= 9
        && /^[1A-Z](?:[.]|\s)\s*\S/.test(xlines[0]))
        cc = xlines[0].charCodeAt(0);
    else
        return false;
    lines = [];
    for (i = 0; i < xlines.length; ++i)
        if ((m = /^[1-9A-Z](?:[.]|\s)\s*(\S.*)\z/.exec(xlines[i]))
            && xlines[i].charCodeAt(0) == cc + i)
            lines.push(m[1]);
        else
            return false;
    if (cc != 49) {
        lines.reverse();
        fieldj.option_letter = String.fromCharCode(cc + lines.length - 1);
    }
    fieldj.options = lines;
    return true;
} */

function option_class_prefix(fieldj) {
    var sv = fieldj.option_class_prefix || "sv";
    if (fieldj.option_letter)
        sv = colors[(colors.indexOf(sv) || 0) ^ 2];
    return sv;
}

function check_change(fid) {
    var fieldj = original[fid] || {}, j, sv;
    if ($.trim($("#shortName_" + fid).val()) != fieldj.name
        || $("#order_" + fid).val() != (fieldj.position || 0)
        || $("#description_" + fid).val() != (fieldj.description || "")
        || $("#authorView_" + fid).val() != (fieldj.view_score || "pc")
        || $.trim($("#options_" + fid).val()) != $.trim(options_to_text(fieldj))
        || ((j = $("#option_class_prefix_" + fid)) && j.length
            && j.val() != option_class_prefix(fieldj))
        || ($("#round_mask_" + fid).val() || 0) != (fieldj.round_mask || 0))
        hiliter("reviewform_container");
}

function check_this_change() {
    check_change(get_fid(this));
}

function fill_order() {
    var i, $c = $("#reviewform_container")[0], $n;
    for (i = 1, $n = $c.firstChild; $n; ++i, $n = $n.nextSibling) {
        $($n).find(".settings_revfieldpos").html(String.fromCharCode(64 + i) + ".");
        $($n).find(".revfield_order").val(i);
    }
    $c = $("#reviewform_removedcontainer")[0];
    for ($n = $c.firstChild; $n; $n = $n.nextSibling)
        $($n).find(".revfield_order").val(0);
}

function fill_field(fid, fieldj) {
    if (fid instanceof Node)
        fid = get_fid(fid);
    fieldj = fieldj || original[fid] || {};
    $("#shortName_" + fid).val(fieldj.name || "");
    $("#order_" + fid).val(fieldj.position || 0);
    $("#description_" + fid).val(fieldj.description || "");
    $("#authorView_" + fid).val(fieldj.view_score || "pc");
    $("#options_" + fid).val(options_to_text(fieldj));
    $("#option_class_prefix_flipped_" + fid).val(fieldj.option_letter ? "1" : "");
    $("#option_class_prefix_" + fid).val(option_class_prefix(fieldj));
    $("#revfield_" + fid + " textarea").trigger("change");
    $("#revfieldview_" + fid).html("").append(create_field_view(fid, fieldj));
    $("#round_mask_" + fid).val(fieldj.round_mask || 0);
    check_change(fid);
    return false;
}

function remove() {
    var $f = $(this).closest(".settings_revfield");
    $f.find(".revfield_order").val(0);
    $f.detach().appendTo("#reviewform_removedcontainer");
    check_change($f.attr("hotcrp_revfield"));
    fill_order();
}

function samples_change() {
    var val = $(this).val();
    if (val == "original")
        fill_field(this);
    else if (val != "x")
        fill_field(this, samples[val]);
}

var revfield_template = '<div id="revfield_$" class="settings_revfield f-contain fold2c errloc_$" hotcrp_revfield="$" hotcrp_fold="1">\
<div id="revfieldpos_$" class="settings_revfieldpos"></div>\
<div id="revfieldview_$" class="settings_revfieldview fn2"></div>\
<div id="revfieldedit_$" class="settings_revfieldedit fx2">\
  <div class="f-i errloc_shortName_$">\
    <input name="shortName_$" id="shortName_$" type="text" class="hottemptext" size="50" style="font-weight:bold" hottemptext="Field name" />\
  </div>\
  <div class="f-i">\
    <button id="moveup_$" class="revfield_moveup" type="button">Move up</button><span class="sep"></span>\
<button id="movedown_$" class="revfield_movedown" type="button">Move down</button><span class="sep"></span>\
    <select name="samples_$" id="samples_$" class="revfield_samples"></select>\
    <span class="sep"></span><button id="remove_$" class="revfield_remove" type="button">Remove</button>\
<input type="hidden" name="order_$" id="order_$" class="revfield_order" value="0" />\
  </div>\
  <div class="f-i">\
    <div class="f-ix">\
      <div class="f-c">Visibility</div>\
      <select name="authorView_$" id="authorView_$" class="reviewfield_authorView">\
        <option value="author">Authors &amp; reviewers</option>\
        <option value="pc">Hidden from authors</option>\
        <option value="admin">Shown only to administrators</option>\
      </select>\
    </div>\
    <div class="f-ix reviewrow_options">\
      <div class="f-c">Colors</div>\
      <select name="option_class_prefix_$" id="option_class_prefix_$" class="reviewfield_option_class_prefix"></select>\
<input type="hidden" name="option_class_prefix_flipped_$" id="option_class_prefix_flipped_$" value="" />\
    </div>\
    <div class="f-ix reviewrow_rounds">\
      <div class="f-c">Rounds</div>\
      <select name="round_mask_$" id="round_mask_$" class="reviewfield_round_mask"></select>\
    </div>\
    <hr class="c" />\
  </div>\
  <div class="f-i errloc_description_$">\
    <div class="f-c">Description</div>\
    <textarea name="description_$" id="description_$" class="reviewtext hottooltip" rows="6" hottooltipcontent="#review_form_caption_description" hottooltipdir="l" hottooltiptype="focus"></textarea>\
  </div>\
  <div class="f-i errloc_options_$ reviewrow_options">\
    <div class="f-c">Options</div>\
    <textarea name="options_$" id="options_$" class="reviewtext hottooltip" rows="6" hottooltipcontent="#review_form_caption_options" hottooltipdir="l" hottooltiptype="focus"></textarea>\
  </div>\
</div><hr class="c" /></div>';

var revfieldview_template = '<div>\
<div class="settings_revfn"></div>\
<div class="settings_revrounds"></div>\
<div class="settings_revvis"></div>\
<div class="settings_reveditor"><button type="button">Edit</button></div>\
<div class="settings_revdata"></div>\
</div>';

function option_value_html(fieldj, value) {
    var cc = 48, ccdelta = 1, t, n;
    if (!value || value < 0)
        return "Unknown";
    if (fieldj.option_letter) {
        cc = fieldj.option_letter.charCodeAt(0) + fieldj.options.length;
        ccdelta = -1;
    }
    t = '<span class="rev_num sv';
    if (value <= fieldj.options.length) {
        if (fieldj.options.length > 1)
            n = Math.floor((value - 1) * 8 / (fieldj.options.length - 1) + 1.5);
        else
            n = 1;
        t += " " + (fieldj.option_class_prefix || "sv") + n;
    }
    return t + '">' + String.fromCharCode(cc + value * ccdelta) + '.</span> ' +
        escape_entities(fieldj.options[value - 1] || "Unknown");
}

function view_unfold(event) {
    foldup(this, event, {n: 2, f: false});
    var $f = $(this).closest(".settings_revfield");
    $f.find("textarea").css("height", "auto").autogrow();
    $f.find("input[hottemptext]").each(mktemptext);
}

function create_field_view(fid, fieldj) {
    var $f = $(revfieldview_template.replace(/\$/g, fid)), $x, i, j, x;
    $f.find(".settings_revfn").text(fieldj.name || "<unnamed>");

    x = "";
    if ((fieldj.view_score || "pc") === "pc")
        x = "(hidden from authors)";
    else if (fieldj.view_score === "admin")
        x = "(shown only to administrators)";
    else if (fieldj.view_score === "secret")
        x = "(secret)";
    $x = $f.find(".settings_revvis");
    x ? $x.text(x) : $x.remove();

    x = "";
    if (fieldj.round_mask && hotcrp_status.rev.roundidx) {
        x = [];
        for (i = 0; (1 << i) <= fieldj.round_mask; ++i)
            if ((fieldj.round_mask & (1 << i))
                && (j = hotcrp_status.rev.roundidx.indexOf(i)) >= 0)
                x.push(hotcrp_status.rev.rounds[j] || "unnamed");
        if (x.length == 1)
            x = "(round " + x[0] + " only)";
        else if (x.length)
            x = "(rounds " + commajoin(x) + ")";
        else
            x = "";
    }
    $x = $f.find(".settings_revrounds");
    x ? $x.text(x) : $x.remove();

    if (fieldj.options) {
        x = [option_value_html(fieldj, 1),
             option_value_html(fieldj, fieldj.options.length)];
        fieldj.option_letter && x.reverse();
    } else
        x = ["Text field"];
    $f.find(".settings_revdata").html(x.join(" … "));

    $f.find("button").click(view_unfold);
    return $f;
}

function move_field(event) {
    var isup = $(this).hasClass("revfield_moveup"),
        $f = $(this).closest(".settings_revfield").detach(),
        fid = $f.attr("hotcrp_revfield"),
        pos = $f.find(".revfield_order").val() | 0,
        $c = $("#reviewform_container")[0], $n, i;
    for (i = 1, $n = $c.firstChild;
         $n && i < (isup ? pos - 1 : pos + 1);
         ++i, $n = $n.nextSibling)
        /* nada */;
    $c.insertBefore($f[0], $n);
    fill_order();
    check_change(fid);
}

function append_field(fid, pos) {
    var $f = $("#revfield_" + fid), i, $j;
    if ($f.length) {
        $f.detach().appendTo("#reviewform_container");
        fill_order();
        return;
    }

    $f = $(revfield_template.replace(/\$/g, fid));
    $f.find(".settings_revfieldpos").html(String.fromCharCode(64 + pos) + ".");

    if (fieldmap[fid]) {
        $j = $f.find(".reviewfield_option_class_prefix");
        for (i = 0; i < colors.length; i += 2)
            $j.append("<option value=\"" + colors[i] + "\">" + colors[i+1] + "</option>");
    } else
        $f.find(".reviewrow_options").remove();

    if (hotcrp_status.rev && hotcrp_status.rev.roundidx
        && hotcrp_status.rev.roundidx.length > 1) {
        var rname = hotcrp_status.rev.rounds, ridx = hotcrp_status.rev.roundidx,
            v, j, text;
        $j = $f.find(".reviewfield_round_mask");
        for (i = 0; i < (1 << ridx.length) - 1;
             i = next_lexicographic_permutation(i, ridx.length)) {
            v = 0, text = [];
            for (j = 0; j < ridx.length; ++j)
                if (i & (1 << j)) {
                    v |= 1 << ridx[j];
                    text.push(rname[j] || "unnamed");
                }
            if (v == 0)
                $j.append("<option value=\"0\">All rounds</option>");
            else if (!(v & (v - 1)))
                $j.append("<option value=\"" + v + "\">" + text[0] + " only</option>");
            else
                $j.append("<option value=\"" + v + "\">" + commajoin(text) + "</option>");
        }
    } else
        $f.find(".reviewrow_rounds").remove();

    var sampleopt = "<option value=\"x\">Load field from library...</option>";
    for (i = 0; i != samples.length; ++i)
        if (!samples[i].options == !fieldmap[fid])
            sampleopt += "<option value=\"" + i + "\">" + samples[i].selector + "</option>";
    $f.find(".revfield_samples").html(sampleopt).on("change", samples_change);

    $f.find(".revfield_remove").on("click", remove);
    $f.find(".revfield_moveup, .revfield_movedown").on("click", move_field);
    $f.find("input, textarea, select").on("change", check_this_change);
    $f.appendTo("#reviewform_container");

    fill_field(fid, original[fid]);
}

function rfs(fieldmapj, originalj, samplesj, errors, request) {
    var i, fid, $j;
    fieldmap = fieldmapj;
    original = originalj;
    samples = samplesj;

    fieldorder = [];
    for (fid in original)
        if (original[fid].position)
            fieldorder.push(fid);
    fieldorder.sort(function (a, b) {
        return original[a].position - original[b].position;
    });

    // construct form
    for (i = 0; i != fieldorder.length; ++i)
        append_field(fieldorder[i], i + 1);

    // highlight errors, apply request
    for (i in request || {}) {
        if (!$("#" + i).length)
            rfs.add(false, i.replace(/^.*_/, ""));
        $("#" + i).val(request[i]);
        hiliter("reviewform_container");
    }
    for (i in errors || {}) {
        $j = $(".errloc_" + i);
        $j.addClass("error");
        foldup($j[0], null, {n: 2, f: false});
    }
};

function do_add(fid) {
    fieldorder.push(fid);
    original[fid] = original[fid] || {};
    original[fid].position = fieldorder.length;
    append_field(fid, fieldorder.length);
    $("#revfieldview_" + fid).find("button").click();
    hiliter("reviewform_container");
    return true;
}

rfs.add = function (has_options, fid) {
    if (fid)
        return do_add(fid);
    // prefer recently removed fields
    var $c = $("#reviewform_removedcontainer")[0], $n, x = [], i;
    for (i = 0, $n = $c.firstChild; $n; ++i, $n = $n.nextSibling)
        x.push([$n.getAttribute("hotcrp_revfield"), i]);
    // otherwise prefer fields that have ever been defined
    for (fid in fieldmap)
        if ($.inArray(fid, fieldorder) < 0)
            x.push([fid, ++i + (original[fid].name && original[fid].name != "Field name" ? 0 : 1000)]);
    x.sort(function (a, b) { return a[1] - b[1]; });
    for (i = 0; i != x.length; ++i)
        if (!fieldmap[x[i][0]] == !has_options)
            return do_add(x[i][0]);
    alert("You’ve reached the maximum number of " + (has_options ? "score fields." : "text fields."));
};

return rfs;
})();


function settings_add_resp_round() {
    var i, j;
    for (i = 1; jQuery("#response_" + i).length; ++i)
        /* do nothing */;
    jQuery("#response_n").before("<div id=\"response_" + i + "\" style=\"padding-top:1em\"></div>");
    j = jQuery("#response_" + i);
    j.html(jQuery("#response_n").html().replace(/_n\"/g, "_" + i + "\""));
    hiliter_children(j);
    j.find("input[hottemptext]").each(mktemptext);
    j.find("textarea").css({height: "auto"}).autogrow().val(jQuery("#response_n textarea").val());
    return false;
}
