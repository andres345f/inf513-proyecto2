import {
  Activity, Apple, Bell, CalendarClock, ChartLine, Circle, CircleUser, ClipboardList, CreditCard,
  FileChartColumn, FileText, HandCoins, House, IdCard, LayoutDashboard, Leaf, MapPinned, Package, Receipt,
  RotateCcw, ScrollText, Send, ShieldCheck, ShoppingBag, ShoppingBasket, ShoppingCart, Sprout, Tags, Tractor,
  TrendingUp, Truck, Undo2, UserRound, Users, Wallet, Warehouse, Wheat,
} from 'lucide-react'

// Nombres de ícono guardados en el menú de la base de datos → componente de ícono.
const ICONOS = {
  inicio: House,
  catalogo: ShoppingBasket,
  perfil: CircleUser,
  cuenta: UserRound,
  notificaciones: Bell,
  carrito: ShoppingCart,
  pedidos: Package,
  pagos: Receipt,
  tarjeta: CreditCard,
  calendario: CalendarClock,
  factura: FileText,
  devolucion: Undo2,
  ficha: IdCard,
  productor: Leaf,
  envio: Send,
  brote: Sprout,
  monedas: HandCoins,
  tendencia: TrendingUp,
  panel: LayoutDashboard,
  portapapeles: ClipboardList,
  billetera: Wallet,
  camion: Truck,
  reembolso: RotateCcw,
  producto: Apple,
  etiquetas: Tags,
  tractor: Tractor,
  mapa: MapPinned,
  trigo: Wheat,
  bolsa: ShoppingBag,
  almacen: Warehouse,
  grafico: ChartLine,
  reporte: FileChartColumn,
  usuarios: Users,
  escudo: ShieldCheck,
  bitacora: ScrollText,
  actividad: Activity,
}

export default function Icono({ nombre, tamano = 20, className }) {
  const Componente = ICONOS[nombre] || Circle
  return <Componente size={tamano} className={className} aria-hidden="true" />
}
