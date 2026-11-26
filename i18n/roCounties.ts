/**
 * WooCommerce's Romanian county codes (WC()->countries->get_states('RO')) with their official names, the values the shop
 * stores in an address's `state`. Proper names: the same in every interface language. Bucharest is "B"; its sector is the
 * city ("Sector 1".."Sector 6"), as at the checkout and for FAN Courier (Staff 2.57.0).
 */
export const RO_COUNTIES: readonly (readonly [string, string])[] = [
  ["AB", "Alba"], ["AR", "Arad"], ["AG", "Argeș"], ["BC", "Bacău"], ["BH", "Bihor"], ["BN", "Bistrița-Năsăud"], ["BT", "Botoșani"], ["BR", "Brăila"],
  ["BV", "Brașov"], ["B", "București"], ["BZ", "Buzău"], ["CL", "Călărași"], ["CS", "Caraș-Severin"], ["CJ", "Cluj"], ["CT", "Constanța"], ["CV", "Covasna"],
  ["DB", "Dâmbovița"], ["DJ", "Dolj"], ["GL", "Galați"], ["GR", "Giurgiu"], ["GJ", "Gorj"], ["HR", "Harghita"], ["HD", "Hunedoara"], ["IL", "Ialomița"],
  ["IS", "Iași"], ["IF", "Ilfov"], ["MM", "Maramureș"], ["MH", "Mehedinți"], ["MS", "Mureș"], ["NT", "Neamț"], ["OT", "Olt"], ["PH", "Prahova"],
  ["SJ", "Sălaj"], ["SM", "Satu Mare"], ["SB", "Sibiu"], ["SV", "Suceava"], ["TR", "Teleorman"], ["TM", "Timiș"], ["TL", "Tulcea"], ["VL", "Vâlcea"],
  ["VS", "Vaslui"], ["VN", "Vrancea"],
];
