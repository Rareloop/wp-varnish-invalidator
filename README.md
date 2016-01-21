# VCL setup

````
# Allow cache invalidation
if (req.method == "BAN") {
  # Same ACL check as above:
  if (!client.ip ~ purge) {
    return(synth(403, "Not allowed."));
  }

  # Compare against the hostname ignoring the port.
  # When using Vagrant we're port forwarding which throws all the
  # matching off
  if(req.http.X-Ban-Method == "regex") {
    ban("req.http.Host ~ ^" + regsuball(regsub(req.http.Host, ":[0-9]+", ""), "(\.|\-)", "\\\1") +
          " && req.url ~ " + req.http.X-Ban-Regex);
  } elseif (req.http.X-Ban-Method == "url") {
    ban("req.http.Host ~ ^" + regsuball(regsub(req.http.Host, ":[0-9]+", ""), "(\.|\-)", "\\\1") +
          " && req.url == " + req.http.X-Ban-Url);
  } else {
    return(synth(400, "Valid X-Ban-Url not provided"));
  }

  # Throw a synthetic page so the
  # request won't go to the backend.
  return(synth(200, "Ban added"));
}
  ````